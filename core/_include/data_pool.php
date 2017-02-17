<?php

/**
 * Data Controlling Module
 * Version 2.7.0 (Nerve Cell)
 *
 * Author Jerry Shaw <jerry-shaw@live.com>
 * Author 秋水之冰 <27206617@qq.com>
 * Author 杨晶 <752050750@qq.com>
 * Author 风雨凌芸 <tianpapawo@live.com>
 *
 * Copyright 2015-2017 Jerry Shaw
 * Copyright 2016-2017 秋水之冰
 * Copyright 2017 杨晶
 * Copyright 2016 风雨凌芸
 *
 * This file is part of ooBase Core.
 *
 * ooBase Core is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ooBase Core is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ooBase Core. If not, see <http://www.gnu.org/licenses/>.
 */
class data_pool
{
    //Original data pool
    public static $pool = [];

    //Result data content
    public static $data = [];

    //Result data format (json/raw)
    public static $format = 'json';

    //Enable/Disable GET Method via HTTP Request
    public static $enable_get = false;

    //Enable/Disable mapping result data to original data pool
    public static $enable_mapping = true;

    //Module list
    private static $module = [];

    //Method list
    private static $method = [];

    //Mapping list
    private static $mapping = [];

    //Initial Data Controlling Module
    //Only static methods are supported
    public static function start()
    {
        //Parse data from HTTP Request
        self::parse_data();
        //Build data structure
        $structure = array_keys(self::$pool);
        //Parse Module & Method list
        foreach (self::$module as $module => $libraries) {
            //Load Module CFG file for the first time
            load_lib($module, 'cfg');
            //Load Libraries
            foreach ($libraries as $library) {
                //Load library file
                $class = load_lib($module, $library);
                //Check the existence of the class and its API content
                if ('' !== $class && isset($class::$api) && is_array($class::$api) && !empty($class::$api)) {
                    //Get api list from API content
                    $api_list = array_keys($class::$api);
                    //Get method list from the class
                    $method_list = get_class_methods($class);
                    //Get the api methods according to the request
                    //All methods will be stored in the intersect list if no method is provided
                    $method_api = !empty(self::$method) ? array_intersect(self::$method, $api_list, $method_list) : array_intersect($api_list, $method_list);
                    //Prepend "init" method which should always run at the first place if exists
                    if (in_array('init', $method_list, true) && !in_array('init', $method_api, true)) array_unshift($method_api, 'init');
                    //Check the property of every method and call it if it is public and static
                    foreach ($method_api as $method) {
                        //Get the intersect list of the data requirement structure
                        $intersect = array_intersect($structure, $class::$api[$method]);
                        //Get the different list of the data requirement structure
                        $difference = array_diff($class::$api[$method], $intersect);
                        //Remove the api method if the data structure is not matched
                        if (empty($difference)) {
                            //Get a reflection object for the class method
                            $reflect = new \ReflectionMethod($class, $method);
                            //Check the visibility and property of the method
                            if ($reflect->isPublic() && $reflect->isStatic()) {
                                //Try to call the method and catch the Exceptions or Errors
                                try {
                                    //Calling method
                                    $result = $class::$method();
                                    //Merge result
                                    if (isset($result)) {
                                        //Save result data
                                        self::$data[$module . '/' . $class . '/' . $method] = $result;
                                        //Check mapping request
                                        if (self::$enable_mapping && isset(self::$mapping[$module . '/' . $class . '/' . $method])) {
                                            //Processing array result to get the final data
                                            if (!empty(self::$mapping[$module . '/' . $class . '/' . $method]['from']) && is_array($result)) {
                                                //Check every key in mapping from request
                                                foreach (self::$mapping[$module . '/' . $class . '/' . $method]['from'] as $key) {
                                                    //Check key's existence
                                                    if (isset($result[$key])) {
                                                        //Switch result data to where we find
                                                        unset($tmp);
                                                        $tmp = $result[$key];
                                                        unset($result);
                                                        $result = $tmp;
                                                    } else {
                                                        //Unset result data if requested key does not exist
                                                        unset($result);
                                                        break;
                                                    }
                                                }
                                            }
                                            //Mapping processed result data to data pool if not null
                                            if (isset($result)) {
                                                //Caution: The data with the same key in data pool will be overwritten if exists
                                                self::$pool[self::$mapping[$module . '/' . $class . '/' . $method]['to']] = $result;
                                                //Rebuild data structure
                                                $structure = array_keys(self::$pool);
                                            }
                                        }
                                    } else continue;
                                } catch (\Throwable | \Exception $exception) {
                                    //Save the Exception or Error Message to the result data pool instead
                                    self::$data[$module . '/' . $class . '/' . $method] = $exception->getMessage();
                                }
                            } else continue;
                        } else continue;
                    }
                } else continue;
            }
        }
        unset($structure, $module, $libraries, $library, $class, $api_list, $method_list, $method_api, $intersect, $difference, $method, $reflect, $result, $key, $tmp);
    }

    /**
     * Parse data from HTTP Request if exists
     */
    private static function parse_data()
    {
        //Get date from HTTP Request
        $data = !self::$enable_get ? $_POST : $_REQUEST;
        //Check "cmd" value which should at least contain "/", or with "," for specific methods calling
        //"cmd" value format should be some string like but no need to be exact as, example as follows:
        //One module calling: "module_1/library_1"
        //One module calling with one method or multiple methods: "module_1/library_1,method_1" or "module_1/library_1,method_1,method_2,method_3,method_4,..."
        //Multiple modules calling: "module_1/library_1,module_2/library_2,..."
        //Multiple modules calling with methods: "module_1/library_1,module_2/library_2,...,method_1,method_2,method_3,method_4,..."
        //Modules with namespace: "module_1/\namespace\library_1" or "module_1/\namespace\library_1,method_1,method_2,method_3,method_4,..."
        //Notice: The key to calling a method in a module is the structure of data. All/Specific methods will only run with the matched data structure.
        if (isset($data['cmd']) && false !== strpos($data['cmd'], '/')) {
            //Set result data format according to the request
            if (isset($data['format']) && in_array($data['format'], ['json', 'raw'], true)) self::$format = &$data['format'];
            //Extract "cmd" values
            if (false !== strpos($data['cmd'], ',')) {
                //Spilt "cmd" value if multiple modules/methods exist with "," and clean them up
                $cmd = explode(',', $data['cmd']);
                $cmd = array_filter($cmd);
                $cmd = array_unique($cmd);
            } else $cmd = [$data['cmd']];
            //Parse "cmd" values
            foreach ($cmd as $item) {
                //Get the position of module path
                $position = strpos($item, '/');
                if (false !== $position) {
                    //Module goes here
                    //Get module and library names
                    $module = substr($item, 0, $position);
                    $library = substr($item, $position + 1);
                    //Make sure the parsed results are available
                    if (false !== $module && false !== $library) {
                        //Add module to "self::$module" if not added
                        if (!isset(self::$module[$module])) self::$module[$module] = [];
                        //Add library to "self::$module" if not added
                        if (!in_array($library, self::$module[$module], true)) self::$module[$module][] = $library;
                    } else continue;
                } else {
                    //Method goes here
                    //Add to "self::$method" if not added
                    if (!in_array($item, self::$method, true)) self::$method[] = $item;
                }
            }
            //Check "map" value which should contain both "/" and ":" when the "cmd" is validated
            //"map" value format should be some string like but no need to be exact as, example as follows:
            //Example: "module_1/library_1/method_1:key_1,module_2/library_2/method_2/result_key:key_2,module_2/library_2/method_3/result_key_1/result_key_2:key_3,..."
            //Example with namespace: "module_1/\namespace\library_1/method_1:key_1,module_2/\namespace\library_2/method_2/result_key:key_2,..."
            //Notice: API running follows the input sequence, the former content will be replaced if the coming one has the same key.
            if (isset($data['map']) && false !== strpos($data['map'], '/') && false !== strpos($data['map'], ':')) {
                //Extract "map" values and clean them up
                if (false !== strpos($data['map'], ',')) {
                    $maps = explode(',', $data['map']);
                    $maps = array_filter($maps);
                    $maps = array_unique($maps);
                } else $maps = [$data['map']];
                //Deeply parse the map values
                foreach ($maps as $value) {
                    //Every map value should contain both "/" and ":"
                    $position = strpos($value, ':');
                    if (false !== strpos($value, '/') && false !== $position) {
                        //Extract and get map "from" and "to"
                        $map_from = substr($value, 0, $position);
                        $map_to = substr($value, $position + 1);
                        //Deeply parse map "from"
                        if (false !== strpos($map_from, '/')) {
                            $keys = explode('/', $map_from);
                            //Map keys should always be greater than 3
                            if (3 <= count($keys)) {
                                $key_from = $keys[0] . '/' . $keys[1] . '/' . $keys[2];
                                unset($keys[0], $keys[1], $keys[2]);
                                $data_from = [];
                                foreach ($keys as $key) $data_from[] = $key;
                                //Save to Mapping List
                                self::$mapping[$key_from] = ['from' => $data_from, 'to' => $map_to];
                            } else continue;
                        } else continue;
                    } else continue;
                }
                unset($maps, $value, $map_from, $map_to, $keys, $key_from, $data_from, $key);
            }
            //Unset some useless values
            unset($cmd, $item, $position, $module, $library, $data['cmd'], $data['map'], $data['format']);
            //Mapping data values
            self::$pool = &$data;
            //Merge "$_FILES" into data pool if exists
            if (!empty($_FILES)) self::$pool = array_merge(self::$pool, $_FILES);
        } else self::$format = 'raw';//Set result data format to "raw" if "cmd" value is not detected
        unset($data);
    }
}
