<?php

namespace kch42\ste;

class VariableNode extends ASTNode
{
    /** @var string */
    public $name;

    /** @var ASTNode[][] */
    public $arrayfields;

    /**
     * @param string $tpl
     * @param int $off
     * @param string $name
     * @param ASTNode[][] $arrayfields
     */
    public function __construct(string $tpl, int $off, string $name, array $arrayfields)
    {
        parent::__construct($tpl, $off);

        $this->name = $name;
        $this->arrayfields = $arrayfields;
    }

    /**
     * @return string
     */
    public function transcompile(): string
    {
        $varaccess = '@$ste->scope[' . (is_numeric($this->name) ? $this->name : '"' . Misc::escape_text($this->name) . '"'). ']';
        foreach ($this->arrayfields as $af) {
            if (
                count($af) == 1
                && ($af[0] instanceof TextNode)
                && is_numeric($af[0]->text)
            ) {
                $varaccess .= '[' . $af[0]->text . ']';
            } else {
                $varaccess .= '[' . implode(".", array_map(function ($node) {
                    if ($node instanceof TextNode) {
                        return "\"" . Misc::escape_text($node->text) . "\"";
                    } elseif ($node instanceof VariableNode) {
                        return $node->transcompile();
                    }
                }, $af)). ']';
            }
        }
        return $varaccess;
    }
}
