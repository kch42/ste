<?php


namespace tests\unit;

use PHPUnit\Framework\TestCase;
use kch42\ste\Parser;
use kch42\ste\TextNode;
use kch42\ste\VariableNode;
use kch42\ste\TagNode;

class ParserTest extends TestCase
{
    /**
     * @dataProvider successfulParsingDataProvider
     */
    public function testSuccessfulParsing(string $source, array $expected)
    {
        $actual = Parser::parse($source, '-');

        self::assertEquals($expected, $actual);
    }

    public function successfulParsingDataProvider()
    {
        return [
            ['', []],

            ['Hello', [
                new TextNode('-', 0, 'Hello'),
            ]],

            ['$foo', [
                new VariableNode('-', 0, 'foo', []),
            ]],

            //01234567890
            ['foo$bar$baz', [
                new TextNode('-', 0, 'foo'),
                new VariableNode('-', 3, 'bar', []),
                new VariableNode('-', 7, 'baz', []),
            ]],

            //012345678
            ['${foo}bar', [
                new VariableNode('-', 0, 'foo', []),
                new TextNode('-', 6, 'bar'),
            ]],

            //012345678
            ['$foo[bar]', [
                new VariableNode('-', 0, 'foo', [
                    [new TextNode('-', 5, 'bar')],
                ]),
            ]],

            //01234567890
            ['${foo[bar]}', [
                new VariableNode('-', 0, 'foo', [
                    [new TextNode('-', 6, 'bar')],
                ]),
            ]],

            //012345678
            ['$foo[$bar]', [
                new VariableNode('-', 0, 'foo', [
                    [new VariableNode('-', 5, 'bar', [])],
                ]),
            ]],

            //012345678901234
            ['$foo[$bar[baz]]', [
                new VariableNode('-', 0, 'foo', [
                    [new VariableNode('-', 5, 'bar', [
                        [new TextNode('-', 10, 'baz')],
                    ])],
                ]),
            ]],

            //012345678901234
            ['$foo[$bar][baz]', [
                new VariableNode('-', 0, 'foo', [
                    [new VariableNode('-', 5, 'bar', [])],
                    [new TextNode('-', 11, 'baz')]
                ]),
            ]],

            //0123456789012345678901
            ['a${b[c$d[e${f}g]][h]}i', [
                new TextNode('-', 0, 'a'),
                new VariableNode('-', 1, 'b', [
                    [
                        new TextNode('-', 5, 'c'),
                        new VariableNode('-', 6, 'd', [
                            [
                                new TextNode('-', 9, 'e'),
                                new VariableNode('-', 10, 'f', []),
                                new TextNode('-', 14, 'g')
                            ]
                        ])
                    ],
                    [new TextNode('-', 18, 'h')],
                ]),
                new TextNode('-', 21, 'i'),
            ]],

            ['<ste:foo />', [
                new TagNode('-', 0, 'foo'),
            ]],

            ['<ste:foo></ste:foo>', [
                new TagNode('-', 0, 'foo'),
            ]],

            //0123456789012345678901
            ['<ste:foo>bar</ste:foo>', [
                new TagNode('-', 0, 'foo', [], [
                    new TextNode('-', 9, 'bar'),
                ]),
            ]],

            //0         1         2         3         4         5         6         7         8         9         0
            //012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234
            ['<ste:foo a="$b[0][$x]" c="\"" d="${e}f"><ste:foo><ste:xyz>abc</ste:xyz><ste:foo />$x</ste:foo></ste:foo>x', [
                new TagNode('-', 0, 'foo', [
                    'a' => [
                        new VariableNode('-', 12, 'b', [
                            [new TextNode('-', 15, '0')],
                            [new VariableNode('-', 18, 'x', [])],
                        ]),
                    ],
                    'c' => [
                        new TextNode('-', 26, '"'),
                    ],
                    'd' => [
                        new VariableNode('-', 33, 'e', []),
                        new TextNode('-', 37, 'f'),
                    ]
                ], [
                    new TagNode('-', 40, 'foo', [], [
                        new TagNode('-', 49, 'xyz', [], [
                            new TextNode('-', 58, 'abc'),
                        ]),
                        new TagNode('-', 71, 'foo'),
                        new VariableNode('-', 82, 'x', []),
                    ]),
                ]),
                new TextNode('-', 104, 'x'),
            ]],

            //0         1         2         3
            //01234567890123456789012345678901234567
            ['foo?{~{$x|eq|\}$y}|b|<ste:foo/>\}}$bar', [
                new TextNode('-', 0, 'foo'),
                new TagNode('-', 3, 'if', [], [
                    new TagNode('-', 5, 'cmp', [
                        'text_a' => [new VariableNode('-', 7, 'x', [])],
                        'op' => [new TextNode('-', 10, 'eq')],
                        'text_b' => [
                            new TextNode('-', 13, '}'),
                            new VariableNode('-', 15, 'y', []),
                        ],
                    ], []),
                    new TagNode('-', 3, 'then', [], [
                        new TextNode('-', 19, 'b'),
                    ]),
                    new TagNode('-', 3, 'else', [], [
                        new TagNode('-', 21, 'foo'),
                        new TextNode('-', 31, '}'),
                    ]),
                ]),
                new VariableNode('-', 34, 'bar', []),
            ]],
        ];
    }
}
