<?php
namespace JMS\Serializer\Annotation;

/**
 * @Annotation
 * @Target({"PROPERTY","METHOD"})
 */
final class RecursionGroups
{
    /** @var array */
    public $groups;
}
