<?php
namespace Asgard\Orm;

/**
 * ORM for related entities.
 * @author Michel Hognerud <michel@hognerud.com>
 */
class CollectionORM extends ORM implements CollectionORMInterface {
	/**
	 * Parent entity.
	 * @var \Asgard\Entity\Entity
	 */
	protected $parent;
	/**
	 * Relation instance.
	 * @var EntityRelation
	 */
	protected $relation;

	/**
	 * Constructor.
	 * @param \Asgard\Entity\Entity $entity            $entity
	 * @param string                                   $relationName
	 * @param DataMapperInterface                      $dataMapper
	 * @param string                                   $locale        default locale
	 * @param string                                   $prefix        tables prefix
	 * @param \Asgard\Common\PaginatorFactoryInterface $paginatorFactory
	 * @param \Asgard\Entity\Definition                $targetDefinition
	 */
	public function __construct(\Asgard\Entity\Entity $entity, $relationName, DataMapperInterface $dataMapper, $locale=null, $prefix=null, \Asgard\Common\PaginatorFactoryInterface $paginatorFactory=null, \Asgard\Entity\Definition $targetDefinition=null) {
		$this->parent = $entity;

		$this->relation = $dataMapper->relation($entity->getDefinition(), $relationName);

		if($targetDefinition !== null) {
			$relation = clone($this->relation);
			$relation->setTargetDefinition($targetDefinition);
			$this->relation = $relation;
		}

		parent::__construct($this->relation->getTargetDefinition(), $dataMapper, $locale, $prefix, $paginatorFactory);

		$reverseRelation = $this->relation->reverse();
		if($reverseRelation->isPolymorphic()) {
			$reverseRelation = clone($reverseRelation);
			$reverseRelation->setTargetDefinition($entity->getDefinition());
		}
		$this->joinToEntity($reverseRelation, $entity);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sync($ids, $groups=[]) {
		if(!$ids)
			$ids = [];
		if(!is_array($ids))
			$ids = [$ids];
		foreach($ids as $k=>$v) {
			if($v instanceof \Asgard\Entity\Entity) {
				if($v->isNew())
					$this->dataMapper->save($v, [], $groups);
				$ids[$k] = ['id' => (int)$v->id, 'class' => $v->getClass()];
			}
			else
				$ids[$k] = ['id' => (int)$v, 'class' => $this->relation->getTargetDefinition()->getClass()];
		}

		$this->clear();

		if(!$ids)
			return $this;

		switch($this->relation->type()) {
			case 'hasOne':
			case 'hasMany':
				$relationDefinition = $this->relation->getTargetDefinition();
				$link = $this->relation->reverse()->getLink();

				foreach($ids as $k=>$v)
					$ids[$k] = $v['id'];
				$newDal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->dataMapper->getTable($relationDefinition));
				$newDal->where('id IN (?)', [$ids]);
				if($this->relation->reverse()->isPolymorphic()) {
					$linkType = $this->relation->reverse()->getLinkType();
					$newDal->update([$link => $this->parent->id, $linkType => get_class($this->parent)]);
				}
				else
					$newDal->update([$link => $this->parent->id]);
				break;
			case 'HMABT':
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->relation->getAssociationTable());
				$i = 1;
				foreach($ids as $entity) {
					$params = [$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $entity['id']];
					if($this->relation->get('sortable'))
						$params[$this->relation->getPositionField()] = $i++;
					if($this->relation->reverse()->isPolymorphic())
						$params[$this->relation->reverse()->getLinkType()] = get_class($this->parent);
					elseif($this->relation->isPolymorphic())
						$params[$this->relation->getLinkType()] = $entity['class'];
					$dal->insert($params);
				}
				break;
			default:
				throw new \Exception('Collection should only be used for hasMany and HMABT relations');
		}

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function add($ids) {
		$res = 0;

		if(!is_array($ids))
			$ids = [$ids];
		foreach($ids as $k=>$id) {
			if($id instanceof \Asgard\Entity\Entity) {
				#if the entity was not persisted yet, persist it before adding the relation
				if(!$id->getParameter('persisted'))
					$this->dataMapper->save($id);
				$ids[$k] = (int)$id->id;
			}
		}

		$dal = $this->dataMapper->getDB()->dal();
		switch($this->relation->type()) {
			case 'hasMany':
				$relationDefinition = $this->relation->getTargetDefinition();
				$table = $this->dataMapper->getTable($relationDefinition);
				$params = [$this->relation->getLink() => $this->parent->id];
				if($this->relation->reverse()->isPolymorphic()) {
					$linkType = $this->relation->reverse()->getLinkType();
					$params[$linkType] = get_class($this->parent);
				}
				foreach($ids as $id)
					$res += $dal->reset()->from($table)->where(['id' => $id])->update($params);#todo update all at once
				break;
			case 'HMABT':
				$table = $this->relation->getAssociationTable();
				$i = 1;
				foreach($ids as $id) {
					$res -= $dal->reset()->from($table)->where([$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id])->delete();
					$params = [$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id];
					if($this->relation->get('sortable'))
						$params[$this->relation->getPositionField()] = $i++;
					if($this->relation->reverse()->isPolymorphic())
						$params[$this->relation->reverse()->getLinkType()] = get_class($this->parent);
					$dal->insert($params);
					$res += 1;
				}
				break;
			default:
				throw new \Exception('Collection only works with hasMany and HMABT');
		}

		return $res;
	}

	/**
	 * {@inheritDoc}
	 */
	public function make(array $params=[]) {
		$new = $this->relation->getTargetDefinition()->make($params);
		$reverse = $this->relation->reverse();
		$revName = $reverse->getName();
		if($reverse->get('many'))
			$new->get($revName)->add($this->parent);
		else
			$new->set($revName, $this->parent);
		return $new;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create(array $params=[], $groups=[]) {
		$new = $this->make($params);
		$this->dataMapper->save($new, [], $groups);
		return $new;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remove($ids) {
		if(!is_array($ids))
			$ids = [$ids];
		foreach($ids as $k=>$id) {
			if($id instanceof \Asgard\Entity\Entity)
				$ids[$k] = $id->id;
		}

		switch($this->relation->type()) {
			case 'hasMany':
				$targetDefinition = $this->relation->getTargetDefinition();
				$dal = $this->dataMapper->getDB()->dal()->from($this->dataMapper->getTable($targetDefinition));
				foreach($ids as $id)
					$dal->where(['id' => $id])->update([$this->relation->getLink() => 0]);
				break;
			case 'HMABT':
				$dal = $this->dataMapper->getDB()->dal()->from($this->relation->getAssociationTable());
				foreach($ids as $id)
					$dal->where([$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id])->delete();
				break;
			default:
				throw new \Exception('Collection only works with hasMany and HMABT');
		}

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear() {
		$type = $this->relation->type();
		switch($type) {
			case 'hasOne':
			case 'hasMany':
				$relationDefinition = $this->relation->getTargetDefinition();
				$link = $this->relation->reverse()->getLink();
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->dataMapper->getTable($relationDefinition));
				if($this->relation->reverse()->isPolymorphic()) {
					$linkType = $this->relation->reverse()->getLinkType();
					$dal->where([$link => $this->parent->id, $linkType => get_class($this->parent)])->update([$link => null, $linkType => null]);
				}
				else
					$dal->where([$link => $this->parent->id])->update([$link => null]);
				break;
			case 'HMABT':
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->relation->getAssociationTable());
				$dal->where($this->relation->getLinkA(), $this->parent->id);
				if($this->relation->reverse()->isPolymorphic())
					$dal->where($this->relation->reverse()->getLinkType(), get_class($this->parent));
				$dal->delete();
				break;
			default:
				throw new \Exception('Collection should only be used for hasMany and HMABT relations');
		}

		return $this;
	}
}
