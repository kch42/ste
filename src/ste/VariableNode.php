<?php

namespace kch42\ste;

class VariableNode extends ASTNode {
    public $name;
    public $arrayfields = array();
    public function transcompile() {
        $varaccess = '@$ste->scope[' . (is_numeric($this->name) ? $this->name : '"' . Misc::escape_text($this->name) . '"'). ']';
        foreach($this->arrayfields as $af) {
            if((count($af) == 1) and ($af[0] instanceof TextNode) and is_numeric($af[0]->text)) {
                $varaccess .= '[' . $af->text . ']';
            } else {
                $varaccess .= '[' . implode(".", array_map(function($node) {
                    if($node instanceof TextNode) {
                        return "\"" . Misc::escape_text($node->text) . "\"";
                    } else if($node instanceof VariableNode) {
                        return $node->transcompile();
                    }
                }, $af)). ']';
            }
        }
        return $varaccess;
    }
}
