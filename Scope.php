<?php

namespace kch42\ste;

class Scope implements \ArrayAccess {
	private $parent = NULL;
	private $vars = array();
	
	private static function parse_name($name) {
		$remain = $name;
		$fields = array();
		
		while($remain !== "") {
			$br_open = strpos($remain, '[');
			if($br_open === false) {
				$fields[] = $remain;
				break;
			}
			
			$br_close = strpos($remain, ']', $br_open);
			if($br_close === false) {
				throw new RuntimeError("Invalid varname \"$name\". Missing closing \"]\".");
			}
			
			$fields[] = substr($remain, 0, $br_open);
			
			$field = substr($remain, $br_open+1, $br_close-$br_open-1);
			$more = substr($remain, $br_close+1);
			
			if(strpos($field, '[') !== false) {
				throw new RuntimeError("A variable field must not contain a '[' character.");
			}
			
			if((strlen($more) > 0) && ($more[0] !== '[')) {
				// TODO: better error message, not very non-programmer friendly...
				throw new RuntimeError("A variable name must be of format name('[' name ']')*.");
			}
			
			$remain = $field . $more;
		}
		
		return $fields;
	}
	
	private function &get_topvar_reference($name, $localonly) {
		if(array_key_exists($name, $this->vars)) {
			$ref = &$this->vars[$name];
			return $ref;
		}
		
		if((!$localonly) && ($this->parent !== NULL)) {
			$ref = &$this->parent->get_topvar_reference($name, $localonly);
			return $ref;
		}

		throw new VarNotInScope();
	}
	
	/*
	 * Function: get_var_reference
	 * Get a reference to a template variable using a variable name.
	 * This can be used, if your custom tag takes a variable name as a parameter.
	 * 
	 * Parameters:
	 * 	$name - The variables name.
	 * 	$create_if_not_exist - Should the variable be created, if it does not exist? Otherwise NULL will be returned, if the variable does not exist.
	 * 
	 * Throws:
	 * 	<RuntimeError> if the variable name can not be parsed (e.g. unbalanced brackets).
	 * 
	 * Returns:
	 * 	A Reference to the variable.
	 */
	public function &get_var_reference($name, $create_if_not_exist, $localonly=false) {
		$nullref = NULL;
		
		$fields = self::parse_name($name);
		if(count($fields) == 0) {
			return $nullref; // TODO: or should we throw an exception here?
		}
		
		$first = $fields[0];
		
		$ref = NULL;
		try {
			$ref = &$this->get_topvar_reference($first, $localonly);
		} catch(VarNotInScope $e) {
			if($create_if_not_exist) {
				$this->vars[$first] = (count($fields) > 0) ? array() : "";
				$ref = &$this->vars[$first];
			} else {
				return $nullref;
			}
		}
		
		for($i = 1; $i < count($fields); $i++) {
			$field = $fields[$i];
			
			if(!is_array($ref)) {
				return $nullref;
			}
			
			if(!array_key_exists($field, $ref)) {
				if(!$create_if_not_exist) {
					return $nullref;
				}
				
				if($i < count($fields) - 1) {
					$ref[$field] = array();
				} else {
					$ref[$field] = "";
				}
			}
			
			$ref = &$ref[$field];
		}
		
		return $ref;
	}
	
	/*
	 * Function: set_var_by_name
	 * Set a template variable by its name.
	 * This can be used, if your custom tag takes a variable name as a parameter.
	 * 
	 * Parameters:
	 * 	$name - The variables name.
	 * 	$val - The new value.
	 * 
	 * Throws:
	 * 	<RuntimeError> if the variable name can not be parsed (e.g. unbalanced brackets).
	 */
	public function set_var_by_name($name, $val) {
		$ref = &$this->get_var_reference($name, true);
		$ref = $val;
	}
	
	public function set_local_var($name, $val) {
		$ref = &$this->get_var_reference($name, true, true);
		$ref = $val;
	}
	
	/*
	 * Function: get_var_by_name
	 * Get a template variable by its name.
	 * This can be used, if your custom tag takes a variable name as a parameter.
	 * 
	 * Parameters:
	 * 	$name - The variables name.
	 * 
	 * Throws:
	 * 	<RuntimeError> if the variable name can not be parsed (e.g. unbalanced brackets).
	 * 
	 * Returns:
	 * 	The variables value.
	 */
	public function get_var_by_name($name) {
		$ref = $this->get_var_reference($name, false);
		return $ref === NULL ? "" : $ref;
	}
	
	public function new_subscope() {
		$o = new self();
		$o->parent = $this;
		return $o;
	}
	
	/* implementing ArrayAccess */
	
	public function offsetSet($offset, $value) {
		$this->set_var_by_name($offset, $value);
	}
	public function offsetGet($offset) {
		return $this->get_var_by_name($offset);
	}
	public function offsetExists($offset) {
		try {
			$this->get_topvar_reference($offset);
			return true;
		} catch(VarNotInScope $e) {
			return false;
		}
	}
	public function offsetUnset($offset) {
		unset($this->vars[$offset]);
	}
}