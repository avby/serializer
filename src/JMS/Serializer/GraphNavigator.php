<?php

namespace JMS\Serializer;

use JMS\Serializer\Construction\ObjectConstructorInterface;
use JMS\Serializer\EventDispatcher\EventDispatcherInterface;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use JMS\Serializer\EventDispatcher\PreDeserializeEvent;
use JMS\Serializer\EventDispatcher\PreSerializeEvent;
use JMS\Serializer\Exception\ExpressionLanguageRequiredException;
use JMS\Serializer\Exception\InvalidArgumentException;
use JMS\Serializer\Exception\LogicException;
use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\Exclusion\DisjunctExclusionStrategy;
use JMS\Serializer\Exclusion\ExpressionLanguageExclusionStrategy;
use JMS\Serializer\Exclusion\GroupsExclusionStrategy;
use JMS\Serializer\Expression\ExpressionEvaluatorInterface;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;
use Metadata\MetadataFactoryInterface;

/**
 * Handles traversal along the object graph.
 *
 * This class handles traversal along the graph, and calls different methods
 * on visitors, or custom handlers to process its nodes.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
final class GraphNavigator implements GraphNavigatorInterface
{
    const DIRECTION_SERIALIZATION = 1;
    const DIRECTION_DESERIALIZATION = 2;

    /**
     * @var ExpressionLanguageExclusionStrategy
     */
    private $expressionExclusionStrategy;

    private $dispatcher;
    private $metadataFactory;
    private $handlerRegistry;
    private $objectConstructor;

    public function __construct(
        MetadataFactoryInterface $metadataFactory,
        HandlerRegistryInterface $handlerRegistry,
        ObjectConstructorInterface $objectConstructor,
        EventDispatcherInterface $dispatcher = null,
        ExpressionEvaluatorInterface $expressionEvaluator = null
    ) {
        $this->dispatcher = $dispatcher;
        $this->metadataFactory = $metadataFactory;
        $this->handlerRegistry = $handlerRegistry;
        $this->objectConstructor = $objectConstructor;
        if ($expressionEvaluator) {
            $this->expressionExclusionStrategy = new ExpressionLanguageExclusionStrategy($expressionEvaluator);
        }
    }

    /**
     * Parses a direction string to one of the direction constants.
     *
     * @param string $dirStr
     *
     * @return integer
     */
    public static function parseDirection($dirStr)
    {
        switch (strtolower($dirStr)) {
            case 'serialization':
                return self::DIRECTION_SERIALIZATION;

            case 'deserialization':
                return self::DIRECTION_DESERIALIZATION;

            default:
                throw new InvalidArgumentException(sprintf('The direction "%s" does not exist.', $dirStr));
        }
    }

    /**
     * Called for each node of the graph that is being traversed.
     *
     * @param mixed      $data the data depends on the direction, and type of visitor
     * @param null|array $type array has the format ["name" => string, "params" => array]
     * @param Context    $context
     * @return mixed the return value depends on the direction, and type of visitor
     */
    public function accept($data, array $type = null, Context $context)
    {
        $visitor = $context->getVisitor();

        // If the type was not given, we infer the most specific type from the
        // input data in serialization mode.
        if (null === $type) {
            if ($context instanceof DeserializationContext) {
                throw new RuntimeException('The type must be given for all properties when deserializing.');
            }

            $typeName = \gettype($data);
            if ('object' === $typeName) {
                $typeName = \get_class($data);
            }

            $type = ['name' => $typeName, 'params' => []];
        }
        // If the data is null, we have to force the type to null regardless of the input in order to
        // guarantee correct handling of null values, and not have any internal auto-casting behavior.
        else if ($context instanceof SerializationContext && null === $data) {
            $type = ['name' => 'NULL', 'params' => []];
        }
        // Sometimes data can convey null but is not of a null type.
        // Visitors can have the power to add this custom null evaluation
        if ($visitor instanceof NullAwareVisitorInterface && $visitor->isNull($data) === true) {
            $type = ['name' => 'NULL', 'params' => []];
        }

        switch ($type['name']) {
            case 'NULL':
                return $visitor->visitNull($data, $type, $context);

            case 'string':
                return $visitor->visitString($data, $type, $context);

            case 'int':
            case 'integer':
                return $visitor->visitInteger($data, $type, $context);

            case 'bool':
            case 'boolean':
                return $visitor->visitBoolean($data, $type, $context);

            case 'double':
            case 'float':
                return $visitor->visitDouble($data, $type, $context);

            case 'array':
                return $visitor->visitArray($data, $type, $context);

            case 'resource':
                $msg = 'Resources are not supported in serialized data.';
                if ($context instanceof SerializationContext && null !== $path = $context->getPath()) {
                    $msg .= ' Path: ' . $path;
                }

                throw new RuntimeException($msg);

            default:
                // TODO: The rest of this method needs some refactoring.
                $this->assertObjectOrCustomHandlerForSerialization($data, $type, $context);

                if ($context instanceof SerializationContext) {
                    if (null !== $data && is_object($data)) {
//                        if ($context->isVisiting($data)) {
//                            return null;
//                        }
                        $context->startVisiting($data);
                    }

                    // If we're serializing a polymorphic type, then we'll be interested in the
                    // metadata for the actual type of the object, not the base class.
                    if (is_object($data) && class_exists($type['name'], false) || interface_exists($type['name'], false)) {
                        if (is_subclass_of($data, $type['name'], false)) {
                            $type = array('name' => \get_class($data), 'params' => array());
                        }
                    }
                } elseif ($context instanceof DeserializationContext) {
                    $context->increaseDepth();
                }

                // Trigger pre-serialization callbacks, and listeners if they exist.
                // Dispatch pre-serialization event before handling data to have ability change type in listener
                if ($context instanceof SerializationContext) {
                    if (null !== $this->dispatcher && $this->dispatcher->hasListeners('serializer.pre_serialize', $type['name'], $context->getFormat())) {
                        $this->dispatcher->dispatch('serializer.pre_serialize', $type['name'], $context->getFormat(), $event = new PreSerializeEvent($context, $data, $type));
                        $type = $event->getType();
                    }
                } elseif ($context instanceof DeserializationContext) {
                    if (null !== $this->dispatcher && $this->dispatcher->hasListeners('serializer.pre_deserialize', $type['name'], $context->getFormat())) {
                        $this->dispatcher->dispatch('serializer.pre_deserialize', $type['name'], $context->getFormat(), $event = new PreDeserializeEvent($context, $data, $type));
                        $type = $event->getType();
                        $data = $event->getData();
                    }
                }

                // First, try whether a custom handler exists for the given type. This is done
                // before loading metadata because the type name might not be a class, but
                // could also simply be an artifical type.
                if (null !== $handler = $this->handlerRegistry->getHandler($context->getDirection(), $type['name'], $context->getFormat())) {
                    $rs = \call_user_func($handler, $visitor, $data, $type, $context);
                    $this->leaveScope($context, $data);

                    return $rs;
                }

                $exclusionStrategy = $context->getExclusionStrategy();

                /** @var $metadata ClassMetadata */
                $metadata = $this->metadataFactory->getMetadataForClass($type['name']);

                if ($metadata->usingExpression && !$this->expressionExclusionStrategy) {
                    throw new ExpressionLanguageRequiredException("To use conditional exclude/expose in {$metadata->name} you must configure the expression language.");
                }

                if ($context instanceof DeserializationContext && !empty($metadata->discriminatorMap) && $type['name'] === $metadata->discriminatorBaseClass) {
                    $metadata = $this->resolveMetadata($data, $metadata);
                }

                if (null !== $exclusionStrategy && $exclusionStrategy->shouldSkipClass($metadata, $context)) {
                    $this->leaveScope($context, $data);

                    return null;
                }

                $context->pushClassMetadata($metadata);

                if ($context instanceof SerializationContext) {
                    foreach ($metadata->preSerializeMethods as $method) {
                        $method->invoke($data);
                    }
                }

                $object = $data;
                if ($context instanceof DeserializationContext) {
                    $object = $this->objectConstructor->construct($visitor, $metadata, $data, $type, $context);
                }

                if (isset($metadata->handlerCallbacks[ $context->getDirection() ][ $context->getFormat() ])) {
                    $rs = $object->{$metadata->handlerCallbacks[ $context->getDirection() ][ $context->getFormat() ]}(
                        $visitor,
                        $context instanceof SerializationContext ? null : $data,
                        $context
                    );
                    $this->afterVisitingObject($metadata, $object, $type, $context);

                    return $context instanceof SerializationContext ? $rs : $object;
                }

                $visitor->startVisitingObject($metadata, $object, $type, $context);
                foreach ($metadata->propertyMetadata as $propertyMetadata) {
                    if (null !== $exclusionStrategy && $exclusionStrategy->shouldSkipProperty($propertyMetadata, $context)) {
                        continue;
                    }

                    if (null !== $this->expressionExclusionStrategy && $this->expressionExclusionStrategy->shouldSkipProperty($propertyMetadata, $context)) {
                        continue;
                    }

                    if ($context instanceof DeserializationContext && $propertyMetadata->readOnly) {
                        continue;
                    }

                    $context->pushPropertyMetadata($propertyMetadata);

                    $originalGroups = $context->attributes->containsKey('groups') ? $context->attributes->get('groups')->get() : null;
                    $this->applyRecursiveGroups($context);

                    $visitor->visitProperty($propertyMetadata, $data, $context);

                    if (null !== $originalGroups) {
                        $this->changeContextGroups($context, $originalGroups);
                    }

                    $context->popPropertyMetadata();
                }

                if ($context instanceof SerializationContext) {
                    $this->afterVisitingObject($metadata, $data, $type, $context);

                    return $visitor->endVisitingObject($metadata, $data, $type, $context);
                }

                $rs = $visitor->endVisitingObject($metadata, $data, $type, $context);
                $this->afterVisitingObject($metadata, $rs, $type, $context);

                return $rs;
        }
    }

    /**
     * Asserts during serialization, that provided data is either an object, or has custom handler registered.
     *
     * @param mixed                   $data
     * @param array                   $type
     * @param \JMS\Serializer\Context $context
     * @throws \LogicException Thrown, when a primitive type has no custom handler registered.
     */
    public function assertObjectOrCustomHandlerForSerialization($data, $type, Context $context)
    {
        //Ok during deserialization
        if ($context->getDirection() === static::DIRECTION_DESERIALIZATION) {
            return;
        }
        //Ok, if data is an object
        if (is_object($data) || $data === null) {
            return;
        }
        //Ok, if custom handler exists
        if (null !== $this->handlerRegistry->getHandler($context->getDirection(), $type['name'], $context->getFormat())) {
            return;
        }

        //Not ok - throw an exception
        throw new LogicException('Expected object but got ' . gettype($data) . '. Do you have the wrong @Type mapping or could this be a Doctrine many-to-many relation?');
    }

    private function resolveMetadata($data, ClassMetadata $metadata)
    {
        switch (true) {
            case \is_array($data) && isset($data[$metadata->discriminatorFieldName]):
                $typeValue = (string)$data[$metadata->discriminatorFieldName];
                break;

            // Check XML attribute without namespace for discriminatorFieldName
            case \is_object($data) && $metadata->xmlDiscriminatorAttribute && null === $metadata->xmlDiscriminatorNamespace && isset($data->attributes()->{$metadata->discriminatorFieldName}):
                $typeValue = (string)$data->attributes()->{$metadata->discriminatorFieldName};
                break;

            // Check XML attribute with namespace for discriminatorFieldName
            case \is_object($data) && $metadata->xmlDiscriminatorAttribute && null !== $metadata->xmlDiscriminatorNamespace && isset($data->attributes($metadata->xmlDiscriminatorNamespace)->{$metadata->discriminatorFieldName}):
                $typeValue = (string)$data->attributes($metadata->xmlDiscriminatorNamespace)->{$metadata->discriminatorFieldName};
                break;

            // Check XML element with namespace for discriminatorFieldName
            case \is_object($data) && !$metadata->xmlDiscriminatorAttribute && null !== $metadata->xmlDiscriminatorNamespace && isset($data->children($metadata->xmlDiscriminatorNamespace)->{$metadata->discriminatorFieldName}):
                $typeValue = (string)$data->children($metadata->xmlDiscriminatorNamespace)->{$metadata->discriminatorFieldName};
                break;

            // Check XML element for discriminatorFieldName
            case \is_object($data) && isset($data->{$metadata->discriminatorFieldName}):
                $typeValue = (string)$data->{$metadata->discriminatorFieldName};
                break;

            default:
                throw new \LogicException(sprintf(
                    'The discriminator field name "%s" for base-class "%s" was not found in input data.',
                    $metadata->discriminatorFieldName,
                    $metadata->name
                ));
        }

        if (!isset($metadata->discriminatorMap[ $typeValue ])) {
            throw new \LogicException(sprintf(
                'The type value "%s" does not exist in the discriminator map of class "%s". Available types: %s',
                $typeValue,
                $metadata->name,
                implode(', ', array_keys($metadata->discriminatorMap))
            ));
        }

        return $this->metadataFactory->getMetadataForClass($metadata->discriminatorMap[ $typeValue ]);
    }

    /**
     * @param Context $context
     */
    private function applyRecursiveGroups(Context $context)
    {
        if ($context->getMetadataStack() && $context->attributes->containsKey('groups')) {
            $groups = array_fill_keys($context->attributes->get('groups')->get(), true);
            $groupModifiers = [];
            $path = '';
            foreach ($context->getMetadataStack() as $metadata) {
                if ($metadata instanceof PropertyMetadata && is_array($metadata->recursionGroups)) {
                    $path = '.' . $metadata->name . $path;
                    $groupModifiers[] = $metadata->recursionGroups;
                }
            }
            foreach (array_reverse($groupModifiers) as $modifier) {
                foreach ($modifier as $ifGroup => $withGroups) {
                    if (isset($groups[ $ifGroup ])) {
                        $groups = $withGroups;
                        break;
                    }
                }
            }
            $this->changeContextGroups($context, array_keys($groups));
        }
    }

    /**
     * @param Context $context
     * @param array   $groups
     */
    private function changeContextGroups(Context $context, array $groups)
    {
        if ($context->attributes->containsKey('groups')) {
            $context->attributes->set('groups', $groups);
        }
        $exclusionStrategy = $context->getExclusionStrategy();
        if ($exclusionStrategy instanceof DisjunctExclusionStrategy) {
            foreach ($exclusionStrategy->getStrategies() as $delegate) {
                if ($delegate instanceof GroupsExclusionStrategy) {
                    $delegate->setGroups($groups);
                }
            }

            return;
        }
        if ($exclusionStrategy instanceof GroupsExclusionStrategy) {
            $exclusionStrategy->setGroups($groups);
        }
    }

    private function leaveScope(Context $context, $data)
    {
        //Visiting does not exist for primitive types
        if (!is_object($data) && $data !== null) {
            return;
        }
        if ($context instanceof SerializationContext) {
            $context->stopVisiting($data);
        } elseif ($context instanceof DeserializationContext) {
            $context->decreaseDepth();
        }
    }

    private function afterVisitingObject(ClassMetadata $metadata, $object, array $type, Context $context)
    {
        $this->leaveScope($context, $object);
        $context->popClassMetadata();

        if ($context instanceof SerializationContext) {
            foreach ($metadata->postSerializeMethods as $method) {
                $method->invoke($object);
            }

            if (null !== $this->dispatcher && $this->dispatcher->hasListeners('serializer.post_serialize', $metadata->name, $context->getFormat())) {
                $this->dispatcher->dispatch('serializer.post_serialize', $metadata->name, $context->getFormat(), new ObjectEvent($context, $object, $type));
            }

            return;
        }

        foreach ($metadata->postDeserializeMethods as $method) {
            $method->invoke($object);
        }

        if (null !== $this->dispatcher && $this->dispatcher->hasListeners('serializer.post_deserialize', $metadata->name, $context->getFormat())) {
            $this->dispatcher->dispatch('serializer.post_deserialize', $metadata->name, $context->getFormat(), new ObjectEvent($context, $object, $type));
        }
    }
}
