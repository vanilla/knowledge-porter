<?php


namespace Vanilla\KnowledgePorter\Tests;

use PHPUnit\Exception;
use PHPUnit\Framework\TestCase;

class ClassTestCase extends TestCase
{
    public function invokeMethod($object, string $methodName, array $params = [])
    {
        try {
            $reflection = new \ReflectionClass(get_class($object));
            $method = $reflection->getMethod($methodName);
            $method->setAccessible(true);

            return $method->invokeArgs($object, $params);
        }catch (Exception $e){
            return null;
        }
    }

    public function getProtectedPrivateProperty($object, string $propertyName)
    {
        try{
            $reflection = new \ReflectionClass(get_class($object));
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);

            return $property->getValue($object);
        }catch (Exception $e){
            return null;
        }
    }

    public function setProtectedPrivateProperty($object, string $propertyName, $value): void
    {
        try{
            $reflection = new \ReflectionClass(get_class($object));
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);

            $property->setValue($object, $value);
        }catch (Exception $e){
            //
        }
    }
}
