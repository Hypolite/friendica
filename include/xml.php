<?php

/**
 * @file include/xml.php
 */


/**
 * @brief This class contain methods to work with XML data.
 */
class xml {
	/**
	 * @brief Creates an XML structure out of a given array.
	 * 
	 * @param array $array The array of the XML structure that will be generated.
	 * @param object $xml The createdXML will be returned by reference.
	 * @param bool $remove_header Should the XML header be removed or not?
	 * @param array $namespaces List of namespaces.
	 * @param bool $root - interally used parameter. Mustn't be used from outside.
	 * 
	 * @return string The created XML.
	 * 
	 * @todo Support conversation of arrays created from xml::to_array()
	 *     (at the moment attributes won't be handled if priority "tag"
	 *     priority "tag" is chosen).
	 */
	public static function from_array($array, &$xml, $remove_header = false, $namespaces = array(), $root = true) {

		if ($root) {
			foreach ($array as $key => $value) {
				foreach ($namespaces as $nskey => $nsvalue) {
					$key .= " xmlns".($nskey == "" ? "":":").$nskey.'="'.$nsvalue.'"';
				}

				if (is_array($value)) {
					$root = new SimpleXMLElement("<".$key."/>");
					self::from_array($value, $root, $remove_header, $namespaces, false);
				} else {
					$root = new SimpleXMLElement("<".$key.">".xmlify($value)."</".$key.">");
				}

				$dom = dom_import_simplexml($root)->ownerDocument;
				$dom->formatOutput = true;
				$xml = $dom;

				$xml_text = $dom->saveXML();

				if ($remove_header) {
					$xml_text = trim(substr($xml_text, 21));
				}

				return $xml_text;
			}
		}

		foreach ($array as $key => $value) {
			if (!isset($element) && isset($xml)) {
				$element = $xml;
			}

			if (is_integer($key)) {
				if (isset($element)) {
					if (is_scalar($value)) {
						$element[0] = $value;
					} else {
						/// @todo: handle nested array values
					}
				}
				continue;
			}

			$element_parts = explode(":", $key);
			if ((count($element_parts) > 1) && isset($namespaces[$element_parts[0]])) {
				$namespace = $namespaces[$element_parts[0]];
			} elseif (isset($namespaces[""])) {
				$namespace = $namespaces[""];
			} else {
				$namespace = NULL;
			}

			// Remove undefined namespaces from the key
			if ((count($element_parts) > 1) && is_null($namespace)) {
				$key = $element_parts[1];
			}

			if (substr($key, 0, 11) == "@attributes") {
				if (!isset($element) || !is_array($value)) {
					continue;
				}

				foreach ($value as $attr_key => $attr_value) {
					$element_parts = explode(":", $attr_key);
					if ((count($element_parts) > 1) && isset($namespaces[$element_parts[0]])) {
						$namespace = $namespaces[$element_parts[0]];
					} else {
						$namespace = NULL;
					}

					$element->addAttribute($attr_key, self::bool2str($attr_value), $namespace);
				}

				continue;
			}

			if (!is_array($value)) {
				$element = $xml->addChild($key, xmlify(self::bool2str($value)), $namespace);
			} elseif (is_array($value)) {
				// More than one node of its kind.
				// If the new array is numeric index, means it is array of nodes of the same kind
				// it should follow the parent key name.
				if (is_numeric(key($value))) {
					foreach ($value as $k => $v) {
						$val = NULL;

						// If there is a '@value' key use it as value.
						if (isset($v['@value'])) {
							$val = xmlify(self::bool2str($v['@value']));
							unset($v['@value']);
						} elseif (!is_array($v)) {
							$val = xmlify(self::bool2str($v));
						}

						$element = $xml->addChild($key, $val, $namespace);
						self::from_array($v, $element, $remove_header, $namespaces, false);
					}
				} else {
					$val = NULL;

					// If there is a '@value' key use it as value.
					if (isset($value['@value'])) {
						$val = xmlify(self::bool2str($value['@value']));
						unset($value['@value']);
					}

					$element = $xml->addChild($key, $val, $namespace);
					self::from_array($value, $element, $remove_header, $namespaces, false);
				}
			}
		}
	}

	/**
	 * @brief Copies an XML object.
	 * 
	 * @param object $source The XML source.
	 * @param object $target The XML target.
	 * @param string $elementname Name of the XML element of the target.
	 */
	public static function copy(&$source, &$target, $elementname) {
		if (count($source->children()) == 0) {
			$target->addChild($elementname, xmlify($source));
		} else {
			$child = $target->addChild($elementname);
			foreach ($source->children() as $childfield => $childentry) {
				self::copy($childentry, $child, $childfield);
			}
		}
	}

	/**
	 * @brief Create an XML element.
	 * 
	 * @param object $doc XML root.
	 * @param string $element XML element name.
	 * @param string $value XML value.
	 * @param array $attributes array containing the attributes.
	 * 
	 * @return object XML element object.
	 */
	public static function create_element($doc, $element, $value = "", $attributes = array()) {
		$element = $doc->createElement($element, xmlify($value));

		foreach ($attributes as $key => $value) {
			$attribute = $doc->createAttribute($key);
			$attribute->value = xmlify($value);
			$element->appendChild($attribute);
		}
		return $element;
	}

	/**
	 * @brief Create an XML and append it to the parent object.
	 *
	 * @param object $doc XML root.
	 * @param object $parent parent object.
	 * @param string $element XML element name.
	 * @param string $value XML value.
	 * @param array $attributes array containing the attributes.
	 */
	public static function add_element($doc, $parent, $element, $value = "", $attributes = array()) {
		$element = self::create_element($doc, $element, $value, $attributes);
		$parent->appendChild($element);
	}

	/**
	 * @brief Convert an XML document to a normalised, case-corrected array
	 *   used by webfinger.
	 * 
	 * @param object $xml_element The XML document.
	 * @param integer $recursion_depth recursion counter for internal use - default 0
	 *    internal use, recursion counter.
	 * 
	 * @return array | sring The array from the xml element or the string.
	 */
	public static function element_to_array($xml_element, &$recursion_depth = 0) {

		// If we're getting too deep, bail out.
		if ($recursion_depth > 512) {
			return(null);
		}

		if (!is_string($xml_element)
			&& !is_array($xml_element)
			&& (get_class($xml_element) == 'SimpleXMLElement')) {
				$xml_element_copy = $xml_element;
				$xml_element = get_object_vars($xml_element);
		}

		if (is_array($xml_element)) {
			$result_array = array();
			if (count($xml_element) <= 0) {
				return (trim(strval($xml_element_copy)));
			}

			foreach ($xml_element as $key => $value) {
				$recursion_depth++;
				$result_array[strtolower($key)] =
					self::element_to_array($value, $recursion_depth);
				$recursion_depth--;
			}
			if ($recursion_depth == 0) {
				$temp_array = $result_array;
				$result_array = array(
					strtolower($xml_element_copy->getName()) => $temp_array,
				);
			}

			return ($result_array);

		} else {
			return (trim(strval($xml_element)));
		}
	}

	/**
	 * @brief Convert the given XML text to an array in the XML structure.
	 * 
	 * xml::to_array() will convert the given XML text to an array in the XML structure.
	 * Link: http://www.bin-co.com/php/scripts/xml2array/
	 * Portions significantly re-written by mike@macgirvin.com for Friendica
	 * (namespaces, lowercase tags, get_attribute default changed, more...).
	 * 
	 * Examples: $array =  xml::to_array(file_get_contents('feed.xml'));
	 *      $array =  xml::to_array(file_get_contents('feed.xml', true, 1, 'attribute'));
	 * 
	 * @param object $contents The XML text.
	 * @param boolean $namespaces True or false include namespace information
	 *      in the returned array as array elements.
	 * @param integer $get_attributes 1 or 0. If this is 1 the function will
	 *      get the attributes as well as the tag values - this results 
	 *      in a different array structure in the return value.
	 * @param string $priority Can be 'tag', 'attribute' or 'mixed'. This will change the way the resulting
	 *      array sturcture. For 'tag', the tags are given more importance.
	 * @param boolean $lowercase Convert tag name to lowercase.
	 * 
	 * @return array The parsed XML in an array form. Use print_r() to see the resulting array structure.
	 */
	public static function to_array($contents, $namespaces = true, $get_attributes = 1, $priority = 'attribute', $lowercase = true) {
		if (!$contents) {
			return array();
		}

		if (!function_exists('xml_parser_create')) {
			logger('xml::to_array: parser function missing');
			return array();
		}


		libxml_use_internal_errors(true);
		libxml_clear_errors();

		if ($namespaces) {
			$parser = @xml_parser_create_ns("UTF-8", ':');
		} else {
			$parser = @xml_parser_create();
		}

		if (! $parser) {
			logger('xml::to_array: xml_parser_create: no resource');
			return array();
		}

		xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
		// http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		@xml_parse_into_struct($parser, trim($contents), $xml_values);
		@xml_parser_free($parser);

		if (! $xml_values) {
			logger('xml::to_array: libxml: parse error: ' . $contents, LOGGER_DATA);
			foreach (libxml_get_errors() as $err) {
				logger('libxml: parse: ' . $err->code . " at " . $err->line . ":" . $err->column . " : " . $err->message, LOGGER_DATA);
			}
			libxml_clear_errors();
			return;
		}

		//Initializations
		$xml_array = array();
		$parents = array();
		$opened_tags = array();
		$arr = array();

		$current = &$xml_array; // Reference

		// Go through the tags.
		// Multiple tags with same name will be turned into an array.
		$repeated_tag_index = array();
		foreach ($xml_values as $data) {
			// Remove existing values, or there will be trouble.
			unset($attributes, $value);

			// This command will extract these variables into the foreach scope
			// tag(string), type(string), level(int), attributes(array).
			extract($data); // We could use the array by itself, but this cooler.

			$result = array();
			$attributes_data = array();

			if (isset($value)) {
				// If there are also attributes we store the value under
				// a '@value' key for the 'mixed' priority mode.
				if ($priority == 'mixed' && isset($attributes) && $get_attributes) {
						$result['@value'] = unxmlify($value);
				} elseif ($priority == 'tag' || $priority == 'mixed') {
					$result = unxmlify($value);
				} else {
					// Put the value in a assoc array
					// if we are in the 'Attribute' mode.
					$result['@value'] = unxmlify($value);
				}
			}

			//Set the attributes too.
			if (isset($attributes) and $get_attributes) {
				foreach ($attributes as $attr => $val) {
					if ($priority == 'tag') {
						$attributes_data[$attr] = $val;
					} else {
						// Set all the attributes in a array called 'attr'.
						$result['@attributes'][$attr] = $val;
					}
				}
			}

			// See tag status and do the needed.
			if ($namespaces && strpos($tag, ':')) {
				$namespc = substr($tag, 0, strrpos($tag, ':'));
				$tag = substr($tag, strlen($namespc)+1);
				$tag = ($lowercase ? strtolower($tag) : $tag);
				$result['@namespace'] = $namespc;
			}

			$tag = ($lowercase ? strtolower($tag) : $tag);

			// The starting of the tag '<tag>'.
			if ($type == "open") {
				$parent[$level-1] = &$current;
				// Insert New tag
				if (!is_array($current) || (!in_array($tag, array_keys($current)))) {
					$current[$tag] = $result;
					if ($attributes_data) {
						$current[$tag. '_attr'] = $attributes_data;
					}
					$repeated_tag_index[$tag.'_'.$level] = 1;

					$current = &$current[$tag];

				// There was another element with the same tag name.
				} else {
					// If there is a 0th element it is already an array.
					if (isset($current[$tag][0])) {
						$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;
						$repeated_tag_index[$tag.'_'.$level]++;

					// This section will make the value an array if multiple tags with the same name appear together.
					} else {
						// This will combine the existing item and the new item together to make an array.
						$current[$tag] = array($current[$tag], $result);
						$repeated_tag_index[$tag.'_'.$level] = 2;

						// The attribute of the last(0th) tag must be moved as well.
						if (isset($current[$tag.'_attr'])) {
							$current[$tag]['0_attr'] = $current[$tag.'_attr'];
							unset($current[$tag.'_attr']);
						}

					}
					$last_item_index = $repeated_tag_index[$tag.'_'.$level]-1;
					$current = &$current[$tag][$last_item_index];
				}

			// Tags that ends in 1 line '<tag />'.
			} elseif ($type == "complete") {
				//See if the key is already taken.
				if (!isset($current[$tag])) { //New Key
					$current[$tag] = $result;
					$repeated_tag_index[$tag.'_'.$level] = 1;
					if ($priority == 'tag' and $attributes_data) {
						$current[$tag. '_attr'] = $attributes_data;
					}

				// If taken, put all things inside a list(array).
				} else {
					// If it is already an array...
					if (isset($current[$tag][0]) and is_array($current[$tag])) {
						// ...push the new element into that array.
						$current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;

						if ($priority == 'tag' and $get_attributes and $attributes_data) {
							$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
						}
						$repeated_tag_index[$tag.'_'.$level]++;

					// If it is not an array...
					} else {
						//...Make it an array using using the existing value and the new value.
						$current[$tag] = array($current[$tag], $result);
						$repeated_tag_index[$tag.'_'.$level] = 1;
						if ($priority == 'tag' and $get_attributes) {
							// The attribute of the last(0th) tag must be moved as well.
							if (isset($current[$tag.'_attr'])) {
								$current[$tag]['0_attr'] = $current[$tag.'_attr'];
								unset($current[$tag.'_attr']);
							}

							if ($attributes_data) {
								$current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
							}
						}
						$repeated_tag_index[$tag.'_'.$level]++; // 0 and 1 indexes are already taken
					}
				}

			// End of tag '</tag>'.
			} elseif ($type == 'close') {
				$current = &$parent[$level-1];
			}
		}

		return($xml_array);
	}

	/**
	 * @brief Delete a node in a XML object.
	 * 
	 * @param object $doc XML document.
	 * @param string $node Node name.
	 */
	public static function deleteNode(&$doc, $node) {
		$xpath = new DomXPath($doc);
		$list = $xpath->query("//".$node);
		foreach ($list as $child) {
			$child->parentNode->removeChild($child);
		}
	}

	/*
	 * @brief Get string representation of boolean value.
	 * 
	 * @param mixed $v String, boolean, or Integer input value.
	 * @return mixed Output value with the correct data type.
	 */
	private static function bool2str($v){
		//Convert boolean to text value.
		$v = ($v === true ? 'true' : $v);
		$v = ($v === false ? 'false' : $v);

		return $v;
	}
}
