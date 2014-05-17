<?php

namespace kch42\ste;

/*
 * Class: Transcompiler
 * Contains the STE transcompiler. You'll only need this, if you want to manually transcompile a STE template.
 */
class Transcompiler {
	private static $builtins = NULL;
	
	public static function init_builtins() {
		if(self::$builtins !== NULL) {
			return;
		}
		
		self::$builtins = array(
			"if" => function($ast) {
				$output = "";
				$condition = array();
				$then = NULL;
				$else = NULL;
				
				foreach($ast->sub as $node) {
					if(($node instanceof TagNode) and ($node->name == "then")) {
						$then = $node->sub;
					} else if(($node instanceof TagNode) and ($node->name == "else")) {
						$else = $node->sub;
					} else {
						$condition[] = $node;
					}
				}
				
				if($then === NULL) {
					throw new ParseCompileError("self::Transcompile error: Missing <ste:then> in <ste:if>.", $ast->tpl, $ast->offset);
				}
				
				$output .= "\$outputstack[] = \"\";\n\$outputstack_i++;\n";
				$output .= self::_transcompile($condition);
				$output .= "\$outputstack_i--;\nif(\$ste->evalbool(array_pop(\$outputstack)))\n{\n";
				$output .= self::indent_code(self::_transcompile($then));
				$output .= "\n}\n";
				if($else !== NULL) {
					$output .= "else\n{\n";
					$output .= self::indent_code(self::_transcompile($else));
					$output .= "\n}\n";
				}
				return $output;
			},
			"cmp" => function($ast) {
				$operators = array(
					array('eq', '=='),
					array('neq', '!='),
					array('lt', '<'),
					array('lte', '<='),
					array('gt', '>'),
					array('gte', '>=')
				);
				
				$code = "";
				
				if(isset($ast->params["var_b"])) {
					list($val, $pre) = self::_transcompile($ast->params["var_b"], true);
					$code .= $pre;
					$b = '$ste->get_var_by_name(' . $val . ')';
				} else if(isset($ast->params["text_b"])) {
					list($b, $pre) = self::_transcompile($ast->params["text_b"], true);
					$code .= $pre;
				} else {
					throw new ParseCompileError("self::Transcompile error: neiter var_b nor text_b set in <ste:cmp>.", $ast->tpl, $ast->offset);
				}
				
				if(isset($ast->params["var_a"])) {
					list($val, $pre) = self::_transcompile($ast->params["var_a"], true);
					$code .= $pre;
					$a = '$ste->get_var_by_name(' . $val . ')';
				} else if(isset($ast->params["text_a"])) {
					list($a, $pre) = self::_transcompile($ast->params["text_a"], true);
					$code .= $pre;
				} else {
					throw new ParseCompileError("self::Transcompile error: neiter var_a nor text_a set in <ste:cmp>.", $ast->tpl, $ast->offset);
				}
				
				if(!isset($ast->params["op"])) {
					throw new ParseCompileError("self::Transcompile error: op not given in <ste:cmp>.", $ast->tpl, $ast->offset);
				}
				if((count($ast->params["op"]) == 1) and ($ast->params["op"][0] instanceof TextNode)) {
					/* Operator is known at compile time, this saves *a lot* of output code! */
					$op = trim($ast->params["op"][0]->text);
					$op_php = NULL;
					foreach($operators as $v) {
						if($v[0] == $op) {
							$op_php = $v[1];
							break;
						}
					}
					if($op_php === NULL) {
						throw new ParseCompileError("self::Transcompile Error: Unknown operator in <ste:cmp>", $ast->tpl, $ast->offset);
					}
					$code .= "\$outputstack[\$outputstack_i] .= (($a) $op_php ($b)) ? 'yes' : '';\n";
				} else {
					list($val, $pre) = self::_transcompile($ast->params["op"], true);
					$code .= $pre . "switch(trim(" . $val . "))\n{\n\t";
					$code .= implode("", array_map(
							function($op) use ($a,$b)
							{
								list($op_stetpl, $op_php) = $op;
								return "case '$op_stetpl':\n\t\$outputstack[\$outputstack_i] .= (($a) $op_php ($b)) ? 'yes' : '';\n\tbreak;\n\t";
							}, $operators
						));
					$code .= "default: throw new \\kch42\\ste\\RuntimeError('Unknown operator in <ste:cmp>.');\n}\n";
				}
				return $code;
			},
			"not" => function($ast) {
				$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
				$code .= self::_transcompile($ast->sub);
				$code .= "\$outputstack_i--;\n\$outputstack[\$outputstack_i] .= (!\$ste->evalbool(array_pop(\$outputstack))) ? 'yes' : '';\n";
				return $code;
			},
			"even" => function($ast) {
				$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
				$code .= self::_transcompile($ast->sub);
				$code .= "\$outputstack_i--;\n\$tmp_even = array_pop(\$outputstack);\n\$outputstack[\$outputstack_i] .= (is_numeric(\$tmp_even) and (\$tmp_even % 2 == 0)) ? 'yes' : '';\n";
				return $code;
			},
			"for" => function($ast) {
				$code = "";
				$loopname = "forloop_" . str_replace(".", "_", uniqid("",true));
				if(empty($ast->params["start"])) {
					throw new ParseCompileError("self::Transcompile error: Missing 'start' parameter in <ste:for>.", $ast->tpl, $ast->offset);
				}
				list($val, $pre) = self::_transcompile($ast->params["start"], true);
				$code .= $pre;
				$code .= "\$${loopname}_start = " . $val . ";\n";
				
				if(empty($ast->params["stop"])) {
					throw new ParseCompileError("self::Transcompile error: Missing 'end' parameter in <ste:for>.", $ast->tpl, $ast->offset);
				}
				list($val, $pre) = self::_transcompile($ast->params["stop"], true);
				$code .= $pre;
				$code .= "\$${loopname}_stop = " . $val . ";\n";
				
				$step = NULL; /* i.e. not known at compilation time */
				if(empty($ast->params["step"])) {
					$step = 1;
				} else if((count($ast->params["step"]) == 1) and ($ast->params["step"][0] instanceof TextNode)) {
					$step = $ast->params["step"][0]->text + 0;
				} else {
					list($val, $pre) = self::_transcompile($ast->params["step"], true);
					$code .= $pre;
					$code .= "\$${loopname}_step = " . $val . ";\n";
				}
				
				if(!empty($ast->params["counter"])) {
					list($val, $pre) = self::_transcompile($ast->params["counter"], true);
					$code .= $pre;
					$code .= "\$${loopname}_countername = " . $val . ";\n";
				}
				
				$loopbody = empty($ast->params["counter"]) ? "" : "\$ste->set_var_by_name(\$${loopname}_countername, \$${loopname}_counter);\n";
				$loopbody .= self::_transcompile($ast->sub);
				$loopbody = self::indent_code("{\n" . self::loopbody(self::indent_code($loopbody)) . "\n}\n");
				
				if($step === NULL) {
					$code .= "if(\$${loopname}_step == 0)\n\tthrow new \\kch42\\ste\\RuntimeError('step can not be 0 in <ste:for>.');\n";
					$code .= "if(\$${loopname}_step > 0)\n{\n";
					$code .= "\tfor(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter <= \$${loopname}_stop; \$${loopname}_counter += \$${loopname}_step)\n";
					$code .= $loopbody;
					$code .= "\n}\nelse\n{\n";
					$code .= "\tfor(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter >= \$${loopname}_stop; \$${loopname}_counter += \$${loopname}_step)\n";
					$code .= $loopbody;
					$code .= "\n}\n";
				} else if($step == 0) {
					throw new ParseCompileError("self::Transcompile Error: step can not be 0 in <ste:for>.", $ast->tpl, $ast->offset);
				} else if($step > 0) {
					$code .= "for(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter <= \$${loopname}_stop; \$${loopname}_counter += $step)\n$loopbody\n";
				} else {
					$code .= "for(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter >= \$${loopname}_stop; \$${loopname}_counter += $step)\n$loopbody\n";
				}
				
				return $code;
			},
			"foreach" => function($ast) {
				$loopname = "foreachloop_" . str_replace(".", "_", uniqid("",true));
				$code = "";
				
				if(empty($ast->params["array"])) {
					throw new ParseCompileError("self::Transcompile Error: array not given in <ste:foreach>.", $ast->tpl, $ast->offset);
				}
				list($val, $pre) = self::_transcompile($ast->params["array"], true);
				$code .= $pre;
				$code .= "\$${loopname}_arrayvar = " . $val . ";\n";
				
				if(empty($ast->params["value"])) {
					throw new ParseCompileError("self::Transcompile Error: value not given in <ste:foreach>.", $ast->tpl, $ast->offset);
				}
				list($val, $pre) = self::_transcompile($ast->params["value"], true);
				$code .= $pre;
				$code .= "\$${loopname}_valuevar = " . $val . ";\n";
				
				if(!empty($ast->params["key"])) {
					list($val, $pre) = self::_transcompile($ast->params["key"], true);
					$code .= $pre;
					$code .= "\$${loopname}_keyvar = " . $val . ";\n";
				}
				
				if(!empty($ast->params["counter"])) {
					list($val, $pre) = self::_transcompile($ast->params["counter"], true);
					$code .= $pre;
					$code .= "\$${loopname}_countervar = " . $val . ";\n";
				}
				
				$loopbody = "";
				$code .= "\$${loopname}_array = \$ste->get_var_by_name(\$${loopname}_arrayvar);\n";
				$code .= "if(!is_array(\$${loopname}_array))\n\t\$${loopname}_array = array();\n";
				if(!empty($ast->params["counter"])) {
					$code .= "\$${loopname}_counter = -1;\n";
					$loopbody .= "\$${loopname}_counter++;\n\$ste->set_var_by_name(\$${loopname}_countervar, \$${loopname}_counter);\n";
				}
				
				$loop = array();
				$else = array();
				foreach($ast->sub as $node) {
					if(($node instanceof TagNode) && ($node->name == "else")) {
						$else = array_merge($else, $node->sub);
					} else {
						$loop[] = $node;
					}
				}
				
				$loopbody .= "\$ste->set_var_by_name(\$${loopname}_valuevar, \$${loopname}_value);\n";
				if(!empty($ast->params["key"])) {
					$loopbody .= "\$ste->set_var_by_name(\$${loopname}_keyvar, \$${loopname}_key);\n";
				}
				$loopbody .= "\n";
				$loopbody .= self::_transcompile($loop);
				$loopbody = "{\n" . self::loopbody(self::indent_code($loopbody)) . "\n}\n";
				
				if(!empty($else)) {
					$code .= "if(empty(\$${loopname}_array))\n{\n";
					$code .= self::indent_code(self::_transcompile($else));
					$code .= "\n}\nelse ";
				}
				$code .= "foreach(\$${loopname}_array as \$${loopname}_key => \$${loopname}_value)\n$loopbody\n";
				
				return $code;
			},
			"infloop" => function($ast) {
				return "while(true)\n{\n" . self::indent_code(self::loopbody(self::indent_code(self::_transcompile($ast->sub)))) . "\n}\n";
			},
			"break" => function($ast) {
				return "throw new \\kch42\\ste\\BreakException();\n";
			},
			"continue" => function($ast) {
				return "throw new \\kch42\\ste\\ContinueException();\n";
			},
			"block" => function($ast) {
				if(empty($ast->params["name"])) {
					throw new ParseCompileError("self::Transcompile Error: name missing in <ste:block>.", $ast->tpl, $ast->offset);
				}
				
				$blknamevar = "blockname_" . str_replace(".", "_", uniqid("", true));
				
				list($val, $code) = self::_transcompile($ast->params["name"], true);
				$code .= "\$${blknamevar} = " . $val . ";\n";
				
				$tmpblk = uniqid("", true);
				$code .= "\$ste->blocks['$tmpblk'] = array_pop(\$outputstack);\n\$ste->blockorder[] = '$tmpblk';\n\$outputstack = array('');\n\$outputstack_i = 0;\n";
				
				$code .= self::_transcompile($ast->sub);
				
				$code .= "\$ste->blocks[\$${blknamevar}] = array_pop(\$outputstack);\n";
				$code .= "if(array_search(\$${blknamevar}, \$ste->blockorder) === false)\n\t\$ste->blockorder[] = \$${blknamevar};\n\$outputstack = array('');\n\$outputstack_i = 0;\n";
				
				return $code;
			},
			"load" => function($ast) {
				if(empty($ast->params["name"])) {
					throw new ParseCompileError("self::Transcompile Error: name missing in <ste:load>.", $ast->tpl, $ast->offset);
				}
				
				list($val, $code) = self::_transcompile($ast->params["name"], true);
				$code .= "\$outputstack[\$outputstack_i] .= \$ste->load(" . $val . ");\n";
				return $code;
			},
			"mktag" => function($ast) {
				$code = "";
				
				if(empty($ast->params["name"])) {
					throw new ParseCompileError("self::Transcompile Error: name missing in <ste:mktag>.", $ast->tpl, $ast->offset);
				}
				
				$fxbody = "\$outputstack = array(''); \$outputstack_i = 0;\$ste->vars['_tag_parameters'] = \$params;\n";
				
				list($tagname, $tagname_pre) = self::_transcompile($ast->params["name"], true);
				
				$usemandatory = "";
				if(!empty($ast->params["mandatory"])) {
					$usemandatory = " use (\$mandatory_params)";
					$code .= "\$outputstack[] = '';\n\$outputstack_i++;\n";
					$code .= self::_transcompile($ast->params["mandatory"]);
					$code .= "\$outputstack_i--;\n\$mandatory_params = explode('|', array_pop(\$outputstack));\n";
					
					$fxbody .= "foreach(\$mandatory_params as \$mp)\n{\n\tif(!isset(\$params[\$mp]))\n\t\tthrow new \\kch42\\ste\\RuntimeError(\"\$mp missing in <ste:\" . $tagname . \">.\");\n}";
				}
				
				$fxbody .= self::_transcompile($ast->sub);
				$fxbody .= "return array_pop(\$outputstack);";
				
				$code .= "\$tag_fx = function(\$ste, \$params, \$sub)" . $usemandatory . "\n{\n" . self::indent_code($fxbody) . "\n};\n";
				$code .= $tagname_pre;
				$code .= "\$ste->register_tag($tagname, \$tag_fx);\n";
				
				return $code;
			},
			"tagcontent" => function($ast) {
				return "\$outputstack[\$outputstack_i] .= \$sub(\$ste);";
			},
			"set" => function($ast) {
				if(empty($ast->params["var"])) {
					throw new ParseCompileError("self::Transcompile Error: var missing in <ste:set>.", $ast->tpl, $ast->offset);
				}
				
				$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
				$code .= self::_transcompile($ast->sub);
				$code .= "\$outputstack_i--;\n";
				
				list($val, $pre) = self::_transcompile($ast->params["var"], true);
				$code .= $pre;
				$code .= "\$ste->set_var_by_name(" . $val . ", array_pop(\$outputstack));\n";
				
				return $code;
			},
			"get" => function($ast) {
				if(empty($ast->params["var"])) {
					throw new ParseCompileError("self::Transcompile Error: var missing in <ste:get>.", $ast->tpl, $ast->offset);
				}
				
				list($val, $pre) = self::_transcompile($ast->params["var"],  true);
				$code .= $pre;
				return "\$outputstack[\$outputstack_i] .= \$ste->get_var_by_name(" . $val . ");";
			},
			"calc" => function($ast) {
				$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
				$code .= self::_transcompile($ast->sub);
				$code .= "\$outputstack_i--;\n\$outputstack[\$outputstack_i] .= \$ste->calc(array_pop(\$outputstack));\n";
				
				return $code;
			}
		);
	}
	
	private static function indent_code($code) {
		return implode(
			"\n",
			array_map(function($line) { return "\t$line"; }, explode("\n", $code))
		);
	}

	private static function loopbody($code) {
		return "try\n{\n" . self::indent_code($code) . "\n}\ncatch(\\kch42\\ste\\BreakException \$e) { break; }\ncatch(\\kch42\\ste\\ContinueException \$e) { continue; }\n";
	}

	private static function _transcompile($ast, $avoid_outputstack = false) { /* The real self::transcompile function, does not add boilerplate code. */
		$code = "";
		
		$text_and_var_buffer = array();
		
		foreach($ast as $node) {
			if($node instanceof TextNode) {
				$text_and_var_buffer[] = '"' . Misc::escape_text($node->text) . '"';
			} else if($node instanceof VariableNode) {
				$text_and_var_buffer[] = $node->transcompile();
			} else if($node instanceof TagNode) {
				if(!empty($text_and_var_buffer)) {
					$code .= "\$outputstack[\$outputstack_i] .= " . implode (" . ", $text_and_var_buffer) . ";\n";
					$text_and_var_buffer = array();
				}
				if(isset(self::$builtins[$node->name])) {
					/* PHP's parser fails once again... Apparently we cannot call a function from an array that is a static property */
					$func = self::$builtins[$node->name];
					$code .= $func($node);
				} else {
					$paramarray = "parameters_" . str_replace(".", "_", uniqid("", true));
					$code .= "\$$paramarray = array();\n";
					
					foreach($node->params as $pname => $pcontent) {
						list($pval, $pre) = self::_transcompile($pcontent, true);
						$code .= $pre . "\$${paramarray}['" . Misc::escape_text($pname) . "'] = " . $pval . ";\n";
					}
					
					$code .= "\$outputstack[\$outputstack_i] .= \$ste->call_tag('" . Misc::escape_text($node->name) . "', \$${paramarray}, ";
					$code .= empty($node->sub) ? "function(\$ste) { return ''; }" : self::transcompile($node->sub);
					$code .= ");\n";
				}
			}
		}
		
		if($avoid_outputstack && ($code == "")) {
			return array(implode (" . ", $text_and_var_buffer), "");
		}
		
		if(!empty($text_and_var_buffer)) {
			$code .= "\$outputstack[\$outputstack_i] .= ". implode (" . ", $text_and_var_buffer) . ";\n";
		}
		
		if($avoid_outputstack) {
			$tmpvar = "tmp_" . str_replace(".", "_", uniqid("",true));
			$code = "\$outputstack[] = '';\n\$outputstack_i++;" . $code;
			$code .= "\$$tmpvar = array_pop(\$outputstack);\n\$outputstack_i--;\n";
			return array("\$$tmpvar", $code);
		}
		
		return $code;
	}

	/*
	 * Function: transcompile
	 * Transcompiles an abstract syntax tree to PHP.
	 * You only need this function, if you want to manually transcompile a template.
	 * 
	 * Parameters:
	 * 	$ast - The abstract syntax tree to transcompile.
	 * 
	 * Returns:
	 * 	PHP code. The PHP code is an anonymous function expecting a <STECore> instance as its parameter and returns a string (everything that was not pached into a section).
	 */
	public static function transcompile($ast) { /* self::Transcompile and add some boilerplate code. */
		$boilerplate = "\$outputstack = array('');\n\$outputstack_i = 0;\n";
		return "function(\$ste)\n{\n" . self::indent_code($boilerplate . self::_transcompile($ast) . "return array_pop(\$outputstack);") . "\n}";
	}
}

Transcompiler::init_builtins();
