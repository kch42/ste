<?php

namespace kch42\ste;

/* Class Calc contains static methods needed by <ste:calc /> */
class Calc {
    private function __construct() {}

    /* We could also just eval() the $infix_math code, but this is much cooler :-D (Parser inception) */
    public static function shunting_yard($infix_math) {
        $operators = array(
            "+" => array("l", 2),
            "-" => array("l", 2),
            "*" => array("l", 3),
            "/" => array("l", 3),
            "^" => array("r", 4),
            "_" => array("r", 5),
            "(" => array("", 0),
            ")" => array("", 0)
        );

        preg_match_all("/\s*(?:(?:[+\\-\\*\\/\\^\\(\\)])|(\\d*[\\.]?\\d*))\\s*/s", $infix_math, $tokens, PREG_PATTERN_ORDER);
        $tokens_raw   = array_filter(array_map('trim', $tokens[0]), function($x) { return ($x === "0") || (!empty($x)); });
        $output_queue = array();
        $op_stack     = array();

        $lastpriority = NULL;
        /* Make - unary, if neccessary */
        $tokens = array();
        foreach($tokens_raw as $token) {
            $priority = isset($operators[$token]) ? $operators[$token][1] : -1;
            if(($token == "-") && (($lastpriority === NULL) || ($lastpriority >= 0))) {
                $priority = $operators["_"][1];
                $tokens[] = "_";
            } else {
                $tokens[] = $token;
            }
            $lastpriority = $priority;
        }

        while(!empty($tokens)) {
            $token = array_shift($tokens);
            if(is_numeric($token)) {
                $output_queue[] = $token;
            } else if($token == "(") {
                $op_stack[] = $token;
            } else if($token == ")") {
                $lbr_found = false;
                while(!empty($op_stack)) {
                    $op = array_pop($op_stack);
                    if($op == "(") {
                        $lbr_found = true;
                        break;
                    }
                    $output_queue[] = $op;
                }
                if(!$lbr_found) {
                    throw new RuntimeError("Bracket mismatch.");
                }
            } else if(!isset($operators[$token])) {
                throw new RuntimeError("Invalid token ($token): Not a number, bracket or operator. Stop.");
            } else {
                $priority = $operators[$token][1];
                if($operators[$token][0] == "l") {
                    while((!empty($op_stack)) and ($priority <= $operators[$op_stack[count($op_stack)-1]][1])) {
                        $output_queue[] = array_pop($op_stack);
                    }
                } else {
                    while((!empty($op_stack)) and ($priority < $operators[$op_stack[count($op_stack)-1]][1])) {
                        $output_queue[] = array_pop($op_stack);
                    }
                }
                $op_stack[] = $token;
            }
        }

        while(!empty($op_stack)) {
            $op = array_pop($op_stack);
            if($op == "(") {
                throw new RuntimeError("Bracket mismatch...");
            }
            $output_queue[] = $op;
        }

        return $output_queue;
    }

    public static function pop2(&$array) {
        $rv = array(array_pop($array), array_pop($array));
        if(array_search(NULL, $rv, true) !== false) {
            throw new RuntimeError("Not enough numbers on stack. Invalid formula.");
        }
        return $rv;
    }

    public static function calc_rpn($rpn) {
        $stack = array();
        foreach($rpn as $token) {
            switch($token) {
            case "+":
                list($b, $a) = self::pop2($stack);
                $stack[] = $a + $b;
                break;
            case "-":
                list($b, $a) = self::pop2($stack);
                $stack[] = $a - $b;
                break;
            case "*":
                list($b, $a) = self::pop2($stack);
                $stack[] = $a * $b;
                break;
            case "/":
                list($b, $a) = self::pop2($stack);
                $stack[] = $a / $b;
                break;
            case "^":
                list($b, $a) = self::pop2($stack);
                $stack[] = pow($a, $b);
                break;
            case "_":
                $a = array_pop($stack);
                if($a === NULL) {
                    throw new RuntimeError("Not enough numbers on stack. Invalid formula.");
                }
                $stack[] = -$a;
                break;
            default:
                $stack[] = $token;
                break;
            }
        }
        return array_pop($stack);
    }

    public static function calc($expr) {
        return self::calc_rpn(self::shunting_yard($expr));
    }
}