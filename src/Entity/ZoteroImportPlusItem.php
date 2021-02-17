<?php
namespace ZoteroImportPlus\Entity;

use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Item;

/**
 * @Entity
 */
class ZoteroImportPlusItem extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @ManyToOne(
     *     targetEntity="ZoteroImportPlus",
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $import;

    /**
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Item",
     *     cascade={"detach"}
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $item;

    /**
     * @Column
     */
    protected $zoteroKey;

    public function getId()
    {
        return $this->id;
    }

    public function setImport(ZoteroImportPlus $import)
    {
        $this->import = $import;
    }

    public function getImport()
    {
        return $this->import;
    }

    public function setItem(Item $item)
    {
        $this->item = $item;
    }

    public function getItem()
    {
        return $this->item;
    }

    public function setZoteroKey($zoteroKey)
    {
        $this->zoteroKey = $zoteroKey;
    }

    public function getZoteroKey()
    {
        return $this->zoteroKey;
    }
}
