<?php

namespace Vanilla\KnowledgePorter\Utils;

/**
 * Class Helpers
 * @package Vanilla\KnowledgePorter\Utils
 */
class Helpers
{
    /**
     * Takes any number of strings and arrays and concatenates all of the string, separated
     * by dots.
     *
     * Example:
     *  Helpers::dot('foo', ['bar', baz']); // foo.bar.baz
     *
     * @return string
     */
    public static function dot(): string
    {
        $args = func_get_args();
        $values = [];
        foreach($args as $arg){
            if(is_array($arg)){
                $arg = array_filter($arg);
                foreach($arg as $v){
                    if(is_string($v) || is_numeric($v)){
                        $values[] = $v;
                    }elseif(is_array($v)){
                        $values[] = self::dot($v);
                    }
                }
            }elseif(is_string($arg) || is_numeric($arg)){
                $values[] = $arg;
            }
        }
        return implode('.', $values);
    }

    /**
     * Takes any number of string and concatenates them, separated by the system DIRECTORY_SEPARATOR
     *
     * Example:
     *  Helpers::path('/usr', 'path/to/dir/', '/path/to/file.txt'); // /usr/path/to/dir/path/to/file.txt
     *
     * @return string
     */
    public static function path(): string
    {
        $args = func_get_args();
        foreach($args as $i => &$arg){
            if(0 === $i){
                $arg = rtrim($arg, DIRECTORY_SEPARATOR);
            }else{
                $arg = trim($arg, DIRECTORY_SEPARATOR);
            }
        }
        return implode(DIRECTORY_SEPARATOR, $args);
    }

    /**
     * Truncates a string to the given length from the right.
     *
     * @param string $input - the string to truncate
     * @param int $length - the length to truncate to
     * @return string
     */
    public static function rtruncate(string $input, int $length = 32): string
    {
        return substr($input, ($length * -1), $length);
    }

    /**
     * Formats a date with the DATE_ATOM format.
     *
     * @param int $date - the timestamp to format.
     * @return string
     */
    public static function dateFormat(int $date): string
    {
        return date(DATE_ATOM, $date);
    }
}
