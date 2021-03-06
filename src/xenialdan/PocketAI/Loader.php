<?php

namespace xenialdan\PocketAI;

use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\level\Level;
use pocketmine\network\mcpe\protocol\SetEntityLinkPacket;
use pocketmine\network\mcpe\protocol\types\EntityLink;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\Server;
use xenialdan\PocketAI\command\SummonCommand;
use xenialdan\PocketAI\entity\Cow;
use xenialdan\PocketAI\entity\ElderGuardian;
use xenialdan\PocketAI\entity\Guardian;
use xenialdan\PocketAI\entity\Horse;
use xenialdan\PocketAI\entity\Squid;
use xenialdan\PocketAI\entity\Wolf;
use xenialdan\PocketAI\listener\AddonEventListener;
use xenialdan\PocketAI\listener\EventListener;
use xenialdan\PocketAI\listener\InventoryEventListener;
use xenialdan\PocketAI\listener\RidableEventListener;

class Loader extends PluginBase{
	const HORSE_JUMP_POWER = 11;
	/** @var Loader */
	private static $instance = null;
	public static $links = [];
	public static $behaviour = [];
	public static $loottables = [];

	/**
	 * Returns an instance of the plugin
	 * @return Loader
	 */
	public static function getInstance(){
		return self::$instance;
	}

	public function onLoad(){
		self::$instance = $this;
	}

	public function onEnable(){
		$this->getServer()->getCommandMap()->register("pocketmine", new SummonCommand("summon"));
		Attribute::addAttribute(Loader::HORSE_JUMP_POWER, "minecraft:horse_jump_power", 0.00, 4.00, 1.00);
		foreach ($this->getServer()->getResourceManager()->getResourceStack() as $resourcePack){//TODO check if the priority is ordered in that way, that the top pack overwrites the lower packs
			if ($resourcePack instanceof ZippedResourcePack){
				$za = new \ZipArchive();

				$za->open($resourcePack->getPath());

				for ($i = 0; $i < $za->numFiles; $i++){
					$stat = $za->statIndex($i);
					if (explode("/", $stat['name'])[0] === "entities"){
						self::$behaviour[str_replace(".json", "", $stat['name'])] = json_decode($za->getFromIndex($i), true);
					} elseif (explode("/", $stat['name'])[0] === "loot_tables"){
						self::$loottables[str_replace(".json", "", $stat['name'])] = json_decode($za->getFromIndex($i), true);
					}
				}

				$za->close();
			}
		}

		$this->registerEntities();
		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
		$this->getServer()->getPluginManager()->registerEvents(new InventoryEventListener($this), $this);
		$this->getServer()->getPluginManager()->registerEvents(new RidableEventListener($this), $this);
		$this->getServer()->getPluginManager()->registerEvents(new AddonEventListener($this), $this);
	}

	public function registerEntities(){
		if (Entity::registerEntity(Guardian::class, true, ["pocketai:guardian", "minecraft:guardian"]))
			$this->getLogger()->notice("Registered AI for: Guardian");
		if (Entity::registerEntity(ElderGuardian::class, true, ["pocketai:elder_guardian", "minecraft:elder_guardian"]))
			$this->getLogger()->notice("Registered AI for: ElderGuardian");
		if (Entity::registerEntity(Cow::class, true, ["pocketai:cow", "minecraft:cow"]))
			$this->getLogger()->notice("Registered AI for: Cow");
		if (Entity::registerEntity(Horse::class, true, ["pocketai:horse", "minecraft:horse"]))
			$this->getLogger()->notice("Registered AI for: Horse");
		if (Entity::registerEntity(Squid::class, true, ["pocketai:squid", "minecraft:squid"]))
			$this->getLogger()->notice("Registered AI for: Squid");
		if (Entity::registerEntity(Wolf::class, true, ["pocketai:wolf", "minecraft:wolf"]))
			$this->getLogger()->notice("Registered AI for: Wolf");
	}

	public static function isRiding(Entity $entity){
		return ($entity->getDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_RIDING) && !is_null(Loader::getLink($entity)));
	}

	/**
	 * @param Entity $main
	 * @param Entity $passenger
	 * @param int $type
	 */
	public static function setEntityLink(Entity $main, Entity $passenger, int $type = 1){
		if ($main->isAlive() and $passenger->isAlive() and $main->getLevel() === $passenger->getLevel()){
			if (self::isRiding($passenger) && $type !== 0) self::setEntityLink($main, $passenger, 0);
			#$main->setDataProperty(Entity::DATA_OWNER_EID, Entity::DATA_TYPE_LONG, $passenger->getId());
			#$passenger->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_RIDING, $type !== 0);
			$passenger->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_RIDING, $type === 0);
			#if($passenger instanceof Player) $passenger->setAllowFlight(false); //TODO this insta-kicks
			switch ($type){
				case 0: {//unlink
					$pk = new SetEntityLinkPacket();
					$pk->link = Loader::getLink($passenger);
					$pk->link->type = $type;
					$pk->link->bool1 = true;
					$main->getLevel()->getServer()->broadcastPacket($main->getLevel()->getPlayers(), $pk);

					Loader::removeLink($pk->link);
					$passenger->setDataProperty(Entity::DATA_RIDER_SEAT_POSITION, Entity::DATA_TYPE_VECTOR3F, [0, $main->getEyeHeight() + ($passenger->getEyeHeight() / 2), 0]);//TODO
					break;
				}
				case 1: {//rider?
					$pk = new SetEntityLinkPacket();
					$pk->link = new EntityLink();
					$pk->link->fromEntityUniqueId = $main->getId();
					$pk->link->toEntityUniqueId = $passenger->getId();
					$pk->link->type = $type;
					$pk->link->bool1 = true;
					$main->getLevel()->getServer()->broadcastPacket($main->getLevel()->getPlayers(), $pk);

					Loader::setLink($pk->link);
					if ($passenger instanceof Player) $passenger->setAllowFlight(true);

					#$pk = new SetEntityLinkPacket();
					#$link = new EntityLink();
					#$link->fromEntityUniqueId = $main->getId();
					#$link->type = $type;
					#$link->toEntityUniqueId = 0;
					#$link->bool1 = true;
					#$main->getLevel()->getServer()->broadcastPacket($main->getLevel()->getPlayers(), $pk);

					#$passenger->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_RIDING, true);
					$main->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_SADDLED, true);
					$passenger->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_CAN_POWER_JUMP, true);
					$passenger->setDataProperty(Entity::DATA_RIDER_SEAT_POSITION, Entity::DATA_TYPE_VECTOR3F, [0, $main->getEyeHeight() + $passenger->getEyeHeight(), 0]);//TODO
					break;
				}
				/*case 2: {//companion? //TODO multi-links
					$pk = new SetEntityLinkPacket();
					$pk->link = new EntityLink();
					$pk->link->fromEntityUniqueId = $main->getId();
					$pk->link->toEntityUniqueId = $passenger->getId();
					$pk->link->type = $type;
					$pk->link->bool1 = true;
					$main->getLevel()->getServer()->broadcastPacket($main->getLevel()->getPlayers(), $pk);

					Loader::setLink($pk->link);
					$passenger->setDataProperty(Entity::DATA_RIDER_SEAT_POSITION, Entity::DATA_TYPE_VECTOR3F, [0, $main->getEyeHeight() + ($passenger->getEyeHeight() / 2), 0]);//TODO
					break;
				}*/
			}
		}
	}

	/**
	 * @param Entity $entity
	 * @return null|EntityLink
	 */
	public static function getLink(Entity $entity): ?EntityLink{
		return Loader::$links[$entity->getId()] ?? null; //TODO multi-links
	}

	/**
	 * @param EntityLink $link
	 */
	public static function setLink(EntityLink $link){
		Loader::$links[$link->toEntityUniqueId] = $link;
	}

	/**
	 * @param EntityLink $link
	 */
	public static function removeLink(EntityLink $link){
		unset(Loader::$links[$link->toEntityUniqueId]);
	}

	/**
	 * @param EntityLink $link
	 * @param Level|null $level
	 * @return null|Entity
	 */
	public static function getEntityLinkMainEntity(EntityLink $link, Level $level = null): ?Entity{
		return Server::getInstance()->findEntity(Loader::$links[$link->toEntityUniqueId], $level);
	}
}