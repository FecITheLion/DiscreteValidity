<?php
declare(strict_types=1);

/**
 * Boolean Logic AST Implementation & Multi-Language Porting Engine
 *
 * Compatible with the App\AST Factory subsystem (AstNode, AstFactory, ASTTraverser,
 * ConstantFolderVisitor, CodeExporter) and usable standalone.
 *
 * Supports all 16-gate Boolean connectives:
 *   Constants : TRUE/1/⊤, FALSE/0/⊥
 *   Unary     : NOT/¬/!/~, BUF
 *   Binary    : AND (∧,&&,&), OR (∨,||,|), XOR (⊻,⊕,^,!=,≠),
 *               XNOR (↔,⇔,<->,<=>,===,==,≡), NAND (⊼,↑), NOR (⊽,↓),
 *               IMPLIES (→,⇒,->,=>), CONVERSE_IMPLIES (←,⇐,<-,<=),
 *               NIMPLIES (↛,⇏), CONVERSE_NIMPLIES (↚,⇍)
 *
 * Exports real anonymous functions accepting N boolean inputs across 18 targets,
 * plus JSON AST, OpenAPI 3.1.0, Truth Tables, and Canonical DNF/CNF forms.
 */

namespace App\AST\Boolean;

interface BooleanNodeInterface
{
    public function evaluate(array $env = []): bool;
    public function optimize(): BooleanNodeInterface;
    public function collectVariables(array &$vars = []): array;
    public function toArray(): array;
    public function toFactoryArray(): array;
    public function toFactoryAstNode(): mixed;
}

abstract class BooleanNode implements BooleanNodeInterface
{
    public function collectVariables(array &$vars = []): array
    {
        return $vars;
    }

    public function toFactoryAstNode(): mixed
    {
        $arr = $this->toFactoryArray();
        if (class_exists('\\App\\AST\\AstNode')) {
            $children = [];
            foreach ($arr['children'] ?? [] as $k => $child) {
                $children[$k] = $child instanceof BooleanNodeInterface ? $child->toFactoryAstNode() : $child;
            }
            return new \App\AST\AstNode(
                type: $arr['type'],
                attributes: $arr['attributes'] ?? [],
                metadata: $arr['metadata'] ?? [],
                children: $children
            );
        }
        return $arr;
    }
}

final class BoolLiteralNode extends BooleanNode
{
    public function __construct(public readonly bool $value) {}

    public function evaluate(array $env = []): bool
    {
        return $this->value;
    }

    public function optimize(): BooleanNodeInterface
    {
        return $this;
    }

    public function toArray(): array
    {
        return ['type' => 'literal', 'value' => $this->value];
    }

    public function toFactoryArray(): array
    {
        return [
            'type' => 'Literal',
            'attributes' => ['value' => $this->value, 'raw' => $this->value ? 'true' : 'false'],
            'metadata' => ['domain' => 'boolean_logic'],
            'children' => [],
        ];
    }
}

final class BoolVarNode extends BooleanNode
{
    public function __construct(public readonly string $name) {}

    public function evaluate(array $env = []): bool
    {
        return (bool)($env[$this->name] ?? false);
    }

    public function optimize(): BooleanNodeInterface
    {
        return $this;
    }

    public function collectVariables(array &$vars = []): array
    {
        if (!in_array($this->name, $vars, true)) {
            $vars[] = $this->name;
        }
        return $vars;
    }

    public function toArray(): array
    {
        return ['type' => 'variable', 'name' => $this->name];
    }

    public function toFactoryArray(): array
    {
        return [
            'type' => 'Identifier',
            'attributes' => ['name' => $this->name],
            'metadata' => ['domain' => 'boolean_logic'],
            'children' => [],
        ];
    }
}

final class BoolUnaryOpNode extends BooleanNode
{
    public readonly string $operator;

    public function __construct(string $operator, public readonly BooleanNodeInterface $operand)
    {
        $this->operator = strtoupper($operator);
    }

    public function evaluate(array $env = []): bool
    {
        $v = $this->operand->evaluate($env);
        return $this->operator === 'BUF' ? $v : !$v;
    }

    public function optimize(): BooleanNodeInterface
    {
        $inner = $this->operand->optimize();
        if ($this->operator === 'BUF') {
            return $inner;
        }
        if ($inner instanceof BoolLiteralNode) {
            return new BoolLiteralNode(!$inner->value);
        }
        // Double negation elimination: ¬(¬A) => A
        if ($this->operator === 'NOT' && $inner instanceof self && $inner->operator === 'NOT') {
            return $inner->operand;
        }
        return new self($this->operator, $inner);
    }

    public function collectVariables(array &$vars = []): array
    {
        $this->operand->collectVariables($vars);
        return $vars;
    }

    public function toArray(): array
    {
        return [
            'type' => 'unary_op',
            'operator' => $this->operator,
            'operand' => $this->operand->toArray(),
        ];
    }

    public function toFactoryArray(): array
    {
        return [
            'type' => 'UnaryExpression',
            'attributes' => ['operator' => $this->operator],
            'metadata' => ['domain' => 'boolean_logic'],
            'children' => ['operand' => $this->operand],
        ];
    }
}

final class BoolBinaryOpNode extends BooleanNode
{
    public readonly string $operator;

    public function __construct(
        string $operator,
        public readonly BooleanNodeInterface $left,
        public readonly BooleanNodeInterface $right
    ) {
        $this->operator = strtoupper($operator);
    }

    public function evaluate(array $env = []): bool
    {
        $l = $this->left->evaluate($env);
        $r = $this->right->evaluate($env);
        return match ($this->operator) {
            'AND' => $l && $r,
            'OR' => $l || $r,
            'XOR' => $l !== $r,
            'XNOR' => $l === $r,
            'NAND' => !($l && $r),
            'NOR' => !($l || $r),
            'IMPLIES' => !$l || $r,
            'CONVERSE_IMPLIES' => $l || !$r,
            'NIMPLIES' => $l && !$r,
            'CONVERSE_NIMPLIES' => !$l && $r,
            default => false,
        };
    }

    public function optimize(): BooleanNodeInterface
    {
        $l = $this->left->optimize();
        $r = $this->right->optimize();

        if ($l instanceof BoolLiteralNode && $r instanceof BoolLiteralNode) {
            return new BoolLiteralNode((new self($this->operator, $l, $r))->evaluate());
        }

        // Boolean identity & annihilation laws
        if ($this->operator === 'AND') {
            if ($l instanceof BoolLiteralNode) return $l->value ? $r : new BoolLiteralNode(false);
            if ($r instanceof BoolLiteralNode) return $r->value ? $l : new BoolLiteralNode(false);
            if ($l->toArray() === $r->toArray()) return $l;
        } elseif ($this->operator === 'OR') {
            if ($l instanceof BoolLiteralNode) return $l->value ? new BoolLiteralNode(true) : $r;
            if ($r instanceof BoolLiteralNode) return $r->value ? new BoolLiteralNode(true) : $l;
            if ($l->toArray() === $r->toArray()) return $l;
        } elseif ($this->operator === 'XOR') {
            if ($l instanceof BoolLiteralNode) return $l->value ? (new BoolUnaryOpNode('NOT', $r))->optimize() : $r;
            if ($r instanceof BoolLiteralNode) return $r->value ? (new BoolUnaryOpNode('NOT', $l))->optimize() : $l;
            if ($l->toArray() === $r->toArray()) return new BoolLiteralNode(false);
        } elseif ($this->operator === 'XNOR') {
            if ($l instanceof BoolLiteralNode) return $l->value ? $r : (new BoolUnaryOpNode('NOT', $r))->optimize();
            if ($r instanceof BoolLiteralNode) return $r->value ? $l : (new BoolUnaryOpNode('NOT', $l))->optimize();
            if ($l->toArray() === $r->toArray()) return new BoolLiteralNode(true);
        } elseif ($this->operator === 'IMPLIES') {
            if ($l instanceof BoolLiteralNode) return $l->value ? $r : new BoolLiteralNode(true);
            if ($r instanceof BoolLiteralNode && $r->value) return new BoolLiteralNode(true);
            if ($r instanceof BoolLiteralNode && !$r->value) return (new BoolUnaryOpNode('NOT', $l))->optimize();
        }

        return new self($this->operator, $l, $r);
    }

    public function collectVariables(array &$vars = []): array
    {
        $this->left->collectVariables($vars);
        $this->right->collectVariables($vars);
        return $vars;
    }

    public function toArray(): array
    {
        return [
            'type' => 'binary_op',
            'operator' => $this->operator,
            'left' => $this->left->toArray(),
            'right' => $this->right->toArray(),
        ];
    }

    public function toFactoryArray(): array
    {
        return [
            'type' => 'BinaryExpression',
            'attributes' => ['operator' => $this->operator],
            'metadata' => ['domain' => 'boolean_logic'],
            'children' => ['left' => $this->left, 'right' => $this->right],
        ];
    }
}

final class BooleanAstFactory
{
    public static function registerSchemas(object $factory): void
    {
        if (!method_exists($factory, 'registerSchema')) {
            return;
        }
        $factory
            ->registerSchema('Literal', [
                'attributes' => ['value' => ['type' => 'bool', 'required' => true], 'raw' => ['type' => 'string']],
                'children' => [],
            ])
            ->registerSchema('Identifier', [
                'attributes' => ['name' => ['type' => 'string', 'required' => true]],
                'children' => [],
            ])
            ->registerSchema('UnaryExpression', [
                'attributes' => ['operator' => ['type' => 'string', 'required' => true]],
                'children' => ['operand' => ['type' => '*', 'required' => true]],
            ])
            ->registerSchema('BinaryExpression', [
                'attributes' => ['operator' => ['type' => 'string', 'required' => true]],
                'children' => [
                    'left' => ['type' => '*', 'required' => true],
                    'right' => ['type' => '*', 'required' => true],
                ],
            ]);
    }

    public static function fromArray(array $data): BooleanNodeInterface
    {
        $type = $data['type'] ?? '';
        if ($type === 'literal' || $type === 'Literal') {
            $val = $data['value'] ?? ($data['attributes']['value'] ?? false);
            return new BoolLiteralNode((bool)$val);
        }
        if ($type === 'variable' || $type === 'Identifier') {
            $name = (string)($data['name'] ?? ($data['attributes']['name'] ?? 'x'));
            return new BoolVarNode($name);
        }
        if ($type === 'unary_op' || $type === 'UnaryExpression') {
            $op = (string)($data['operator'] ?? ($data['attributes']['operator'] ?? 'NOT'));
            $operand = $data['operand'] ?? ($data['children']['operand'] ?? ['type' => 'literal', 'value' => false]);
            return new BoolUnaryOpNode($op, self::fromArray($operand));
        }
        if ($type === 'binary_op' || $type === 'BinaryExpression') {
            $op = (string)($data['operator'] ?? ($data['attributes']['operator'] ?? 'AND'));
            $left = $data['left'] ?? ($data['children']['left'] ?? ['type' => 'literal', 'value' => false]);
            $right = $data['right'] ?? ($data['children']['right'] ?? ['type' => 'literal', 'value' => false]);
            return new BoolBinaryOpNode($op, self::fromArray($left), self::fromArray($right));
        }
        throw new \InvalidArgumentException("Unsupported Boolean AST node type: '{$type}'.");
    }
}

final class BooleanParser
{
    private static array $precedence = [
        'XNOR' => 1,
        'IMPLIES' => 2,
        'CONVERSE_IMPLIES' => 2,
        'NIMPLIES' => 2,
        'CONVERSE_NIMPLIES' => 2,
        'OR' => 3,
        'NOR' => 3,
        'XOR' => 3,
        'AND' => 4,
        'NAND' => 4,
        'NOT' => 5,
        'BUF' => 5,
    ];

    public static function tokenize(string $expr): array
    {
        $pattern = '/\s*(<->|<=>|->|=>|<-|<=|&&|\|\||!=|===|==|¬|!|~|∧|∨|⊻|⊕|⊼|⊽|→|⇒|←|⇐|↛|⇏|↚|⇍|↔|⇔|≡|≠|⊤|⊥|↑|↓|\(|\)|[A-Za-z_][A-Za-z0-9_]*|[01])\s*/u';
        preg_match_all($pattern, $expr, $matches);
        $tokens = [];
        foreach ($matches[1] ?? [] as $tok) {
            $u = strtoupper($tok);
            if ($tok === '(' || $tok === ')') {
                $tokens[] = ['type' => 'paren', 'val' => $tok];
            } elseif (in_array($u, ['TRUE', '1', '⊤'], true)) {
                $tokens[] = ['type' => 'literal', 'val' => true];
            } elseif (in_array($u, ['FALSE', '0', '⊥'], true)) {
                $tokens[] = ['type' => 'literal', 'val' => false];
            } elseif (in_array($u, ['NOT', '¬', '!', '~'], true)) {
                $tokens[] = ['type' => 'unary', 'op' => 'NOT'];
            } elseif ($u === 'BUF') {
                $tokens[] = ['type' => 'unary', 'op' => 'BUF'];
            } elseif (in_array($u, ['AND', '∧', '&&', '&'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'AND'];
            } elseif (in_array($u, ['OR', '∨', '||', '|'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'OR'];
            } elseif (in_array($u, ['XOR', '⊻', '⊕', '^', '!=', '≠'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'XOR'];
            } elseif (in_array($u, ['XNOR', 'NXOR', 'EQUIV', '↔', '⇔', '<->', '<=>', '===', '==', '≡'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'XNOR'];
            } elseif (in_array($u, ['NAND', '⊼', '↑'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'NAND'];
            } elseif (in_array($u, ['NOR', '⊽', '↓'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'NOR'];
            } elseif (in_array($u, ['IMPLIES', 'IMP', '→', '⇒', '->', '=>'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'IMPLIES'];
            } elseif (in_array($u, ['CIMP', 'CONVERSE_IMPLIES', '←', '⇐', '<-', '<='], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'CONVERSE_IMPLIES'];
            } elseif (in_array($u, ['NIMP', 'NIMPLIES', '↛', '⇏'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'NIMPLIES'];
            } elseif (in_array($u, ['CNIMP', 'CONVERSE_NIMPLIES', '↚', '⇍'], true)) {
                $tokens[] = ['type' => 'binary', 'op' => 'CONVERSE_NIMPLIES'];
            } else {
                $tokens[] = ['type' => 'var', 'name' => $tok];
            }
        }
        return $tokens;
    }

    /**
     * @return array{ast: BooleanNodeInterface, variables: string[]}
     */
    public static function parse(string $expr): array
    {
        $tokens = self::tokenize($expr);
        if (empty($tokens)) {
            throw new \InvalidArgumentException('Empty boolean expression.');
        }
        $pos = 0;
        $n = count($tokens);
        $vars = [];

        $peek = static function () use (&$tokens, &$pos, $n) {
            return $pos < $n ? $tokens[$pos] : null;
        };
        $consume = static function () use (&$tokens, &$pos, $n) {
            if ($pos >= $n) {
                throw new \InvalidArgumentException('Unexpected end of boolean expression.');
            }
            return $tokens[$pos++];
        };

        $parseLevel = null;
        $parsePrimary = static function () use (&$peek, &$consume, &$parseLevel, &$vars): BooleanNodeInterface {
            $t = $peek();
            if ($t === null) {
                throw new \InvalidArgumentException('Unexpected end of boolean expression.');
            }
            if ($t['type'] === 'unary') {
                $consume();
                return new BoolUnaryOpNode($t['op'], $parseLevel(5));
            }
            if ($t['type'] === 'paren' && $t['val'] === '(') {
                $consume();
                $node = $parseLevel(1);
                $close = $peek();
                if ($close === null || $close['type'] !== 'paren' || $close['val'] !== ')') {
                    throw new \InvalidArgumentException('Missing closing parenthesis ")".');
                }
                $consume();
                return $node;
            }
            if ($t['type'] === 'literal') {
                $consume();
                return new BoolLiteralNode((bool)$t['val']);
            }
            if ($t['type'] === 'var') {
                $consume();
                $name = $t['name'];
                if (!in_array($name, $vars, true)) {
                    $vars[] = $name;
                }
                return new BoolVarNode($name);
            }
            throw new \InvalidArgumentException('Unexpected token: ' . json_encode($t));
        };

        $parseLevel = static function (int $minPrec) use (&$peek, &$consume, &$parseLevel, &$parsePrimary): BooleanNodeInterface {
            if ($minPrec >= 5) {
                return $parsePrimary();
            }
            $left = $parsePrimary();
            while (true) {
                $t = $peek();
                if ($t === null || $t['type'] !== 'binary') {
                    break;
                }
                $prec = self::$precedence[$t['op']] ?? 1;
                if ($prec < $minPrec) {
                    break;
                }
                $consume();
                $nextMin = ($t['op'] === 'IMPLIES') ? $prec : ($prec + 1);
                $right = $parseLevel($nextMin);
                $left = new BoolBinaryOpNode($t['op'], $left, $right);
            }
            return $left;
        };

        $ast = $parseLevel(1);
        if ($pos < $n) {
            throw new \InvalidArgumentException('Unexpected trailing token near index ' . $pos);
        }
        return ['ast' => $ast, 'variables' => $vars];
    }
}

final class BooleanTruthTable
{
    public static function generate(BooleanNodeInterface $ast, ?array $vars = null, int $maxVars = 8): array
    {
        $vars = $vars ?? $ast->collectVariables();
        $n = count($vars);
        if ($n > $maxVars) {
            return [
                'truncated' => true,
                'variable_count' => $n,
                'total_rows' => 0,
                'true_count' => 0,
                'false_count' => 0,
                'classification' => 'TOO_MANY_VARIABLES',
                'dnf' => '—',
                'cnf' => '—',
                'rows' => [],
            ];
        }

        $total = 1 << $n;
        $rows = [];
        $trueCount = 0;
        $minterms = [];
        $maxterms = [];

        for ($mask = 0; $mask < $total; $mask++) {
            $env = [];
            $dnfParts = [];
            $cnfParts = [];
            for ($b = 0; $b < $n; $b++) {
                $bitVal = (($mask >> ($n - 1 - $b)) & 1) === 1;
                $v = $vars[$b];
                $env[$v] = $bitVal;
                $dnfParts[] = $bitVal ? $v : '¬' . $v;
                $cnfParts[] = $bitVal ? '¬' . $v : $v;
            }
            $res = $ast->evaluate($env);
            if ($res) {
                $trueCount++;
                if ($n > 0) $minterms[] = '(' . implode(' ∧ ', $dnfParts) . ')';
            } elseif ($n > 0) {
                $maxterms[] = '(' . implode(' ∨ ', $cnfParts) . ')';
            }
            $rows[] = ['inputs' => $env, 'result' => $res];
        }

        $classification = ($trueCount === $total) ? 'TAUTOLOGY' : (($trueCount === 0) ? 'CONTRADICTION' : 'CONTINGENT');
        return [
            'truncated' => false,
            'variable_count' => $n,
            'total_rows' => $total,
            'true_count' => $trueCount,
            'false_count' => $total - $trueCount,
            'classification' => $classification,
            'dnf' => $minterms ? implode(' ∨ ', $minterms) : '⊥ (FALSE)',
            'cnf' => $maxterms ? implode(' ∧ ', $maxterms) : '⊤ (TRUE)',
            'rows' => $rows,
        ];
    }
}

final class BooleanCodeExporter
{
    public const SUPPORTED_LANGUAGES = [
        'javascript', 'node', 'typescript', 'python', 'php', 'rust', 'go',
        'cpp', 'c', 'csharp', 'java', 'ruby', 'haskell', 'sql', 'wasm',
        'latex', 'json', 'openapi'
    ];

    public static function compileExpr(BooleanNodeInterface $node, string $lang): string
    {
        $lang = strtolower(trim($lang));
        if ($node instanceof BoolLiteralNode) {
            return match ($lang) {
                'python', 'haskell' => $node->value ? 'True' : 'False',
                'sql' => $node->value ? 'TRUE' : 'FALSE',
                'wasm', 'wat' => $node->value ? 'i32.const 1' : 'i32.const 0',
                'latex' => $node->value ? '\\top' : '\\bot',
                default => $node->value ? 'true' : 'false',
            };
        }

        if ($node instanceof BoolVarNode) {
            return match ($lang) {
                'php' => '(bool)$' . $node->name,
                'python' => 'bool(' . $node->name . ')',
                'javascript', 'node', 'typescript' => 'Boolean(' . $node->name . ')',
                default => $node->name,
            };
        }

        if ($node instanceof BoolUnaryOpNode) {
            $sub = self::compileExpr($node->operand, $lang);
            if ($node->operator === 'BUF') return $sub;
            if ($lang === 'python' || $lang === 'haskell') return '(not ' . $sub . ')';
            if ($lang === 'sql') return '(NOT ' . $sub . ')';
            if ($lang === 'latex') return '(\\neg ' . $sub . ')';
            if ($lang === 'php' && $node->operand instanceof BoolVarNode) return '!' . $sub;
            return '(!' . $sub . ')';
        }

        if ($node instanceof BoolBinaryOpNode) {
            $op = $node->operator;
            if ($op === 'NAND') {
                return self::compileExpr(new BoolUnaryOpNode('NOT', new BoolBinaryOpNode('AND', $node->left, $node->right)), $lang);
            }
            if ($op === 'NOR') {
                return self::compileExpr(new BoolUnaryOpNode('NOT', new BoolBinaryOpNode('OR', $node->left, $node->right)), $lang);
            }
            if ($op === 'IMPLIES' && $lang !== 'latex') {
                return self::compileExpr(new BoolBinaryOpNode('OR', new BoolUnaryOpNode('NOT', $node->left), $node->right), $lang);
            }
            if ($op === 'CONVERSE_IMPLIES' && $lang !== 'latex') {
                return self::compileExpr(new BoolBinaryOpNode('OR', $node->left, new BoolUnaryOpNode('NOT', $node->right)), $lang);
            }
            if ($op === 'NIMPLIES' && $lang !== 'latex') {
                return self::compileExpr(new BoolBinaryOpNode('AND', $node->left, new BoolUnaryOpNode('NOT', $node->right)), $lang);
            }
            if ($op === 'CONVERSE_NIMPLIES' && $lang !== 'latex') {
                return self::compileExpr(new BoolBinaryOpNode('AND', new BoolUnaryOpNode('NOT', $node->left), $node->right), $lang);
            }

            $l = self::compileExpr($node->left, $lang);
            $r = self::compileExpr($node->right, $lang);
            $strictEq = in_array($lang, ['javascript', 'node', 'typescript', 'php'], true);

            return match ($op) {
                'AND' => $lang === 'latex' ? "({$l} \\land {$r})" : ($lang === 'python' ? "({$l} and {$r})" : ($lang === 'sql' ? "({$l} AND {$r})" : "({$l} && {$r})")),
                'OR' => $lang === 'latex' ? "({$l} \\lor {$r})" : ($lang === 'python' ? "({$l} or {$r})" : ($lang === 'sql' ? "({$l} OR {$r})" : "({$l} || {$r})")),
                'XOR' => $lang === 'latex' ? "({$l} \\oplus {$r})" : ($lang === 'haskell' ? "({$l} /= {$r})" : ($lang === 'sql' ? "(({$l} OR {$r}) AND NOT ({$l} AND {$r}))" : ($strictEq ? "({$l} !== {$r})" : "({$l} != {$r})"))),
                'XNOR' => $lang === 'latex' ? "({$l} \\leftrightarrow {$r})" : ($lang === 'sql' ? "(({$l} AND {$r}) OR (NOT {$l} AND NOT {$r}))" : ($strictEq ? "({$l} === {$r})" : "({$l} == {$r})")),
                'IMPLIES' => "({$l} \\to {$r})",
                'CONVERSE_IMPLIES' => "({$l} \\leftarrow {$r})",
                'NIMPLIES' => "({$l} \\nrightarrow {$r})",
                'CONVERSE_NIMPLIES' => "({$l} \\nleftarrow {$r})",
                default => "({$l} && {$r})",
            };
        }

        return 'false';
    }

    private static function compileWasmWat(BooleanNodeInterface $ast, array $vars): string
    {
        $varIdx = array_flip($vars);
        $emit = static function (BooleanNodeInterface $n) use (&$emit, $varIdx): string {
            if ($n instanceof BoolLiteralNode) {
                return '    i32.const ' . ($n->value ? '1' : '0') . "\n";
            }
            if ($n instanceof BoolVarNode) {
                $idx = $varIdx[$n->name] ?? 0;
                return "    local.get {$idx}\n    i32.const 0\n    i32.ne\n";
            }
            if ($n instanceof BoolUnaryOpNode) {
                $sub = $emit($n->operand);
                return $n->operator === 'BUF' ? $sub : ($sub . "    i32.eqz\n");
            }
            if ($n instanceof BoolBinaryOpNode) {
                $op = $n->operator;
                if ($op === 'NAND') return $emit(new BoolUnaryOpNode('NOT', new BoolBinaryOpNode('AND', $n->left, $n->right)));
                if ($op === 'NOR') return $emit(new BoolUnaryOpNode('NOT', new BoolBinaryOpNode('OR', $n->left, $n->right)));
                if ($op === 'IMPLIES') return $emit(new BoolBinaryOpNode('OR', new BoolUnaryOpNode('NOT', $n->left), $n->right));
                if ($op === 'CONVERSE_IMPLIES') return $emit(new BoolBinaryOpNode('OR', $n->left, new BoolUnaryOpNode('NOT', $n->right)));
                if ($op === 'NIMPLIES') return $emit(new BoolBinaryOpNode('AND', $n->left, new BoolUnaryOpNode('NOT', $n->right)));
                if ($op === 'CONVERSE_NIMPLIES') return $emit(new BoolBinaryOpNode('AND', new BoolUnaryOpNode('NOT', $n->left), $n->right));
                $l = $emit($n->left);
                $r = $emit($n->right);
                return match ($op) {
                    'AND' => $l . $r . "    i32.and\n",
                    'OR' => $l . $r . "    i32.or\n",
                    'XOR' => $l . $r . "    i32.xor\n",
                    'XNOR' => $l . $r . "    i32.eq\n",
                    default => $l . $r . "    i32.and\n",
                };
            }
            return "    i32.const 0\n";
        };
        $params = implode(' ', array_map(fn($v) => "(param \${$v} i32)", $vars));
        return "(module\n  (func " . ($params ? $params . ' ' : '') . "(result i32)\n" . $emit($ast) . "  )\n)";
    }

    public static function toOpenApi(BooleanNodeInterface $ast, ?array $vars = null, ?string $classification = null): array
    {
        $vars = $vars ?? $ast->collectVariables();
        $properties = [];
        $example = [];
        foreach ($vars as $v) {
            $properties[$v] = ['type' => 'boolean'];
            $example[$v] = true;
        }
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Boolean Logic Anonymous Evaluator API',
                'version' => '1.0.0',
            ],
            'paths' => [
                '/evaluate' => [
                    'post' => [
                        'summary' => 'Evaluate boolean expression over ' . count($vars) . ' input(s)',
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => empty($properties) ? new \stdClass() : $properties,
                                        'required' => $vars,
                                        'additionalProperties' => false,
                                    ],
                                    'example' => empty($example) ? new \stdClass() : $example,
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Evaluated boolean result (true/false)',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'result' => ['type' => 'boolean'],
                                            ],
                                            'required' => ['result'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'x-boolean-ast' => $ast->toArray(),
            'x-classification' => $classification,
        ];
    }

    public static function getDownloadMeta(string $lang): array
    {
        $lang = strtolower(trim($lang));
        return match ($lang) {
            'php' => ['filename' => 'boolean_eval.php', 'ext' => 'php', 'mime' => 'application/x-httpd-php'],
            'csharp', 'cs' => ['filename' => 'Program.cs', 'ext' => 'cs', 'mime' => 'text/plain'],
            'javascript', 'js' => ['filename' => 'boolean_eval.js', 'ext' => 'js', 'mime' => 'text/javascript'],
            'node', 'nodejs' => ['filename' => 'boolean_eval.mjs', 'ext' => 'mjs', 'mime' => 'text/javascript'],
            'typescript', 'ts' => ['filename' => 'boolean_eval.ts', 'ext' => 'ts', 'mime' => 'text/typescript'],
            'python', 'py' => ['filename' => 'boolean_eval.py', 'ext' => 'py', 'mime' => 'text/x-python'],
            'rust', 'rs' => ['filename' => 'main.rs', 'ext' => 'rs', 'mime' => 'text/rust'],
            'go' => ['filename' => 'main.go', 'ext' => 'go', 'mime' => 'text/x-go'],
            'cpp', 'c++' => ['filename' => 'main.cpp', 'ext' => 'cpp', 'mime' => 'text/x-c++src'],
            'c' => ['filename' => 'main.c', 'ext' => 'c', 'mime' => 'text/x-csrc'],
            'java' => ['filename' => 'Program.java', 'ext' => 'java', 'mime' => 'text/x-java-source'],
            'ruby', 'rb' => ['filename' => 'boolean_eval.rb', 'ext' => 'rb', 'mime' => 'text/x-ruby'],
            'haskell', 'hs' => ['filename' => 'Main.hs', 'ext' => 'hs', 'mime' => 'text/x-haskell'],
            'sql' => ['filename' => 'boolean_eval.sql', 'ext' => 'sql', 'mime' => 'application/sql'],
            'wasm', 'wat' => ['filename' => 'boolean_eval.wat', 'ext' => 'wat', 'mime' => 'text/plain'],
            'latex' => ['filename' => 'boolean_eval.tex', 'ext' => 'tex', 'mime' => 'application/x-tex'],
            'openapi' => ['filename' => 'boolean_openapi.json', 'ext' => 'json', 'mime' => 'application/json'],
            'json', 'json_ast' => ['filename' => 'boolean_ast.json', 'ext' => 'json', 'mime' => 'application/json'],
            default => ['filename' => 'boolean_eval.txt', 'ext' => 'txt', 'mime' => 'text/plain'],
        };
    }

    public static function export(BooleanNodeInterface $ast, string $lang, ?array $vars = null): string
    {
        $vars = array_values($vars ?? $ast->collectVariables());
        $n = count($vars);
        $lang = strtolower(trim($lang));

        if ($lang === 'json' || $lang === 'json_ast') {
            return (string)json_encode(['parameters' => $vars, 'ast' => $ast->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        if ($lang === 'openapi') {
            $tt = BooleanTruthTable::generate($ast, $vars);
            return (string)json_encode(self::toOpenApi($ast, $vars, $tt['classification']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        if ($lang === 'wasm' || $lang === 'wat') {
            return self::compileWasmWat($ast, $vars);
        }

        // Raw boolean expression for languages where lambda parameters use plain identifiers
        $plainExpr = self::compileExpr($ast, match ($lang) {
            'javascript', 'js', 'node', 'nodejs', 'typescript', 'ts' => 'cpp',
            'python', 'py' => 'haskell',
            default => $lang,
        });

        switch ($lang) {
            case 'php': {
                $body = self::compileExpr($ast, 'php');
                if ($n === 0) {
                    return "<?php\nreturn (static fn(): bool => (bool)({$body}))();\n";
                }
                $bindings = array_map(
                    fn($v, $idx) => "\${$v} = (bool)(\${$v} ?? \$args[{$idx}] ?? \$_REQUEST[{$idx}] ?? \$_REQUEST['{$v}'] ?? false);",
                    $vars,
                    array_keys($vars)
                );
                $params = implode(', ', array_map(fn($v) => "bool \${$v}", $vars));
                $callArgs = implode(', ', array_map(fn($v) => "\${$v}", $vars));
                return implode("\n", array_merge(
                    [
                        "<?php",
                        "\$args = function_exists('func_get_args') ? (@func_get_args() ?: []) : [];",
                        "// Fetch already-set variables, passed args, or \$_REQUEST up to arity {$n}, defaulting to false",
                    ],
                    $bindings,
                    [
                        "\$evaluate = static fn({$params}): bool => (bool)({$body});",
                        "return (bool)\$evaluate({$callArgs});",
                        ""
                    ]
                ));
            }

            case 'csharp':
            case 'cs': {
                if ($n === 0) {
                    return "using System;\n\npublic class Program\n{\n    public static bool Evaluate() => ((Func<bool>)(() => (bool)({$plainExpr})))();\n\n    public static void Main(string[] args)\n    {\n        Console.WriteLine(Evaluate());\n    }\n}\n";
                }
                $argFetchers = implode("\n        ", array_map(
                    fn($i) => "bool arg{$i} = args.Length > {$i} && bool.TryParse(args[{$i}], out bool tmp{$i}) && tmp{$i};",
                    range(0, $n - 1)
                ));
                $funcGenerics = implode(', ', array_fill(0, $n + 1, 'bool'));
                $paramList = implode(', ', $vars);
                $callArgs = implode(', ', array_map(fn($i) => "arg{$i}", range(0, $n - 1)));
                return "using System;\n\npublic class Program\n{\n    public static bool Evaluate(string[] args)\n    {\n        {$argFetchers}\n        Func<{$funcGenerics}> evaluate = ({$paramList}) => (bool)({$plainExpr});\n        return evaluate({$callArgs});\n    }\n\n    public static void Main(string[] args)\n    {\n        Console.WriteLine(Evaluate(args ?? Array.Empty<string>()));\n    }\n}\n";
            }

            case 'javascript':
            case 'js': {
                $body = self::compileExpr($ast, 'javascript');
                $params = implode(', ', $vars);
                if ($n === 0) {
                    return "const result = (() => Boolean({$body}))();\nconsole.log(result);\n";
                }
                $fetchLines = implode("\n", array_map(
                    fn($v, $i) => "const _in{$i} = toBool(globalThis['{$v}'] ?? argv[{$i}]);",
                    $vars,
                    array_keys($vars)
                ));
                $callArgs = implode(', ', array_map(fn($i) => "_in{$i}", range(0, $n - 1)));
                return "const toBool = (v) => (typeof v === 'boolean' ? v : ['true', '1', 'yes'].includes(String(v ?? '').toLowerCase()));\nconst argv = (typeof process !== 'undefined' && Array.isArray(process.argv)) ? process.argv.slice(2) : [];\n{$fetchLines}\nconst result = Boolean((({$params}) => Boolean({$body}))({$callArgs}));\nconsole.log(result);\n";
            }

            case 'node':
            case 'nodejs': {
                $body = self::compileExpr($ast, 'node');
                $params = implode(', ', $vars);
                if ($n === 0) {
                    return "export const result = (() => Boolean({$body}))();\nconsole.log(result);\n";
                }
                $fetchLines = implode("\n", array_map(
                    fn($v, $i) => "const _in{$i} = toBool(globalThis['{$v}'] ?? argv[{$i}]);",
                    $vars,
                    array_keys($vars)
                ));
                $callArgs = implode(', ', array_map(fn($i) => "_in{$i}", range(0, $n - 1)));
                return "const toBool = (v) => (typeof v === 'boolean' ? v : ['true', '1', 'yes'].includes(String(v ?? '').toLowerCase()));\nconst argv = process.argv.slice(2);\n{$fetchLines}\nexport const result = Boolean((({$params}) => Boolean({$body}))({$callArgs}));\nconsole.log(result);\n";
            }

            case 'typescript':
            case 'ts': {
                $body = self::compileExpr($ast, 'typescript');
                $params = implode(', ', array_map(fn($v) => "{$v}: boolean", $vars));
                if ($n === 0) {
                    return "export const result: boolean = ((): boolean => Boolean({$body}))();\nconsole.log(result);\n";
                }
                $fetchLines = implode("\n", array_map(
                    fn($v, $i) => "const _in{$i}: boolean = toBool((globalThis as Record<string, unknown>)['{$v}'] ?? argv[{$i}]);",
                    $vars,
                    array_keys($vars)
                ));
                $callArgs = implode(', ', array_map(fn($i) => "_in{$i}", range(0, $n - 1)));
                return "const toBool = (v: unknown): boolean => (typeof v === 'boolean' ? v : ['true', '1', 'yes'].includes(String(v ?? '').toLowerCase()));\nconst argv: string[] = (typeof process !== 'undefined' && Array.isArray(process.argv)) ? process.argv.slice(2) : [];\n{$fetchLines}\nexport const result: boolean = (({$params}): boolean => Boolean({$body}))({$callArgs});\nconsole.log(result);\n";
            }

            case 'python':
            case 'py': {
                $body = self::compileExpr($ast, 'python');
                $lambda = empty($vars) ? "lambda: bool({$body})" : ("lambda " . implode(', ', $vars) . ": bool({$body})");
                if ($n === 0) {
                    return "result: bool = ({$lambda})()\nprint(result)\n";
                }
                $fetchLines = implode("\n", array_map(
                    fn($v, $i) => "_in{$i} = _to_bool(globals().get('{$v}', sys.argv[" . ($i + 1) . "] if len(sys.argv) > " . ($i + 1) . " else False))",
                    $vars,
                    array_keys($vars)
                ));
                $callArgs = implode(', ', array_map(fn($i) => "_in{$i}", range(0, $n - 1)));
                return "import sys\n\n_to_bool = lambda v: v if isinstance(v, bool) else str(v).strip().lower() in (\"true\", \"1\", \"yes\")\n{$fetchLines}\nresult: bool = bool(({$lambda})({$callArgs}))\nprint(result)\n";
            }

            case 'rust':
            case 'rs': {
                $params = implode(', ', array_map(fn($v) => "{$v}: bool", $vars));
                if ($n === 0) {
                    return "fn main() {\n    let result: bool = (|| -> bool { {$plainExpr} })();\n    println!(\"{}\", result);\n}\n";
                }
                $argParses = implode("\n    ", array_map(
                    fn($i) => "let arg{$i}: bool = args.get({$i}).map(|s| s.eq_ignore_ascii_case(\"true\") || s == \"1\").unwrap_or(false);",
                    range(0, $n - 1)
                ));
                $callArgs = implode(', ', array_map(fn($i) => "arg{$i}", range(0, $n - 1)));
                return "fn main() {\n    let args: Vec<String> = std::env::args().skip(1).collect();\n    {$argParses}\n    let evaluate = |{$params}| -> bool { {$plainExpr} };\n    let result: bool = evaluate({$callArgs});\n    println!(\"{}\", result);\n}\n";
            }

            case 'go': {
                $params = implode(', ', array_map(fn($v) => "{$v} bool", $vars));
                if ($n === 0) {
                    return "package main\n\nimport \"fmt\"\n\nfunc main() {\n\tresult := (func() bool { return {$plainExpr} })()\n\tfmt.Println(result)\n}\n";
                }
                $parses = implode("\n\t", array_map(
                    fn($i) => "arg{$i} := fetchBool(" . ($i + 1) . ")",
                    range(0, $n - 1)
                ));
                $callArgs = implode(', ', array_map(fn($i) => "arg{$i}", range(0, $n - 1)));
                return "package main\n\nimport (\n\t\"fmt\"\n\t\"os\"\n\t\"strconv\"\n)\n\nfunc main() {\n\tfetchBool := func(idx int) bool {\n\t\tif len(os.Args) <= idx { return false }\n\t\tv, err := strconv.ParseBool(os.Args[idx])\n\t\treturn err == nil && v\n\t}\n\t{$parses}\n\tresult := (func({$params}) bool { return {$plainExpr} })({$callArgs})\n\tfmt.Println(result)\n}\n";
            }

            case 'cpp':
            case 'c++': {
                $params = implode(', ', array_map(fn($v) => "bool {$v}", $vars));
                if ($n === 0) {
                    return "#include <iostream>\n\nint main() {\n    bool result = ([]() -> bool { return {$plainExpr}; })();\n    std::cout << (result ? \"true\" : \"false\") << \"\\n\";\n    return 0;\n}\n";
                }
                $callArgs = implode(', ', array_map(fn($i) => "fetchBool(" . ($i + 1) . ")", range(0, $n - 1)));
                return "#include <iostream>\n#include <string>\n\nint main(int argc, char* argv[]) {\n    auto fetchBool = [&](int idx) -> bool {\n        if (argc <= idx) return false;\n        std::string s(argv[idx]);\n        return s == \"true\" || s == \"1\";\n    };\n    bool result = ([]({$params}) -> bool { return {$plainExpr}; })({$callArgs});\n    std::cout << (result ? \"true\" : \"false\") << \"\\n\";\n    return 0;\n}\n";
            }

            case 'c': {
                $params = $n === 0 ? 'void' : implode(', ', array_map(fn($v) => "bool {$v}", $vars));
                if ($n === 0) {
                    return "#include <stdbool.h>\n#include <stdio.h>\n\nstatic inline bool evaluate(void) { return (bool)({$plainExpr}); }\n\nint main(void) {\n    bool result = evaluate();\n    printf(\"%s\\n\", result ? \"true\" : \"false\");\n    return 0;\n}\n";
                }
                $callArgs = implode(', ', array_map(fn($i) => "fetch_bool(argc, argv, " . ($i + 1) . ")", range(0, $n - 1)));
                return "#include <stdbool.h>\n#include <stdio.h>\n#include <string.h>\n\nstatic bool fetch_bool(int argc, char** argv, int idx) { return argc > idx && (strcmp(argv[idx], \"true\") == 0 || strcmp(argv[idx], \"1\") == 0); }\nstatic inline bool evaluate({$params}) { return (bool)({$plainExpr}); }\n\nint main(int argc, char** argv) {\n    bool (*eval_fn)({$params}) = evaluate;\n    bool result = eval_fn({$callArgs});\n    printf(\"%s\\n\", result ? \"true\" : \"false\");\n    return 0;\n}\n";
            }

            case 'java': {
                if ($n === 0) {
                    return "import java.util.function.BooleanSupplier;\n\npublic class Program {\n    public static void main(String[] args) {\n        boolean result = ((BooleanSupplier)(() -> {$plainExpr})).getAsBoolean();\n        System.out.println(result);\n    }\n}\n";
                }
                $ifaceParams = implode(', ', array_map(fn($v) => "boolean {$v}", $vars));
                $lambdaParams = implode(', ', $vars);
                $callArgs = implode(', ', array_map(fn($i) => "(args.length > {$i} && Boolean.parseBoolean(args[{$i}]))", range(0, $n - 1)));
                return "public class Program {\n    @FunctionalInterface\n    interface BoolEvaluator {\n        boolean apply({$ifaceParams});\n    }\n\n    public static void main(String[] args) {\n        BoolEvaluator evaluate = ({$lambdaParams}) -> ({$plainExpr});\n        boolean result = evaluate.apply({$callArgs});\n        System.out.println(result);\n    }\n}\n";
            }

            case 'ruby':
            case 'rb': {
                $body = self::compileExpr($ast, 'ruby');
                $params = implode(', ', $vars);
                if ($n === 0) {
                    return "result = (->() { !!({$body}) }).call\nputs result\n";
                }
                $fetchLines = implode("\n", array_map(
                    fn($v, $i) => "in_{$i} = to_bool.call(defined?({$v}) ? {$v} : ARGV[{$i}])",
                    $vars,
                    array_keys($vars)
                ));
                $callArgs = implode(', ', array_map(fn($i) => "in_{$i}", range(0, $n - 1)));
                return "to_bool = ->(v) { v == true || %w[true 1 yes].include?(v.to_s.downcase) }\n{$fetchLines}\nresult = (->({$params}) { !!({$body}) }).call({$callArgs})\nputs result\n";
            }

            case 'haskell':
            case 'hs': {
                $body = self::compileExpr($ast, 'haskell');
                $hsVars = array_map(fn($v) => 'v_' . strtolower($v), $vars);
                $hsExpr = $body;
                foreach ($vars as $idx => $v) {
                    $hsExpr = preg_replace('/\b' . preg_quote($v, '/') . '\b/', $hsVars[$idx], $hsExpr) ?? $hsExpr;
                }
                if ($n === 0) {
                    return "module Main where\n\nevaluate :: Bool\nevaluate = (\\_ -> {$hsExpr}) ()\n\nmain :: IO ()\nmain = print evaluate\n";
                }
                $lambdaParams = implode(' ', $hsVars);
                $typeSig = implode(' -> ', array_fill(0, $n + 1, 'Bool'));
                $callArgs = implode(' ', array_map(fn($i) => "(argBool args {$i})", range(0, $n - 1)));
                return "module Main where\n\nimport System.Environment (getArgs)\nimport Data.Char (toLower)\n\nargBool :: [String] -> Int -> Bool\nargBool xs i = i < length xs && map toLower (xs !! i) `elem` [\"true\", \"1\"]\n\nevaluate :: {$typeSig}\nevaluate = (\\{$lambdaParams} -> {$hsExpr})\n\nmain :: IO ()\nmain = do\n  args <- getArgs\n  print (evaluate {$callArgs})\n";
            }

            case 'sql': {
                $body = self::compileExpr($ast, 'sql');
                foreach ($vars as $v) {
                    $body = preg_replace('/\b' . preg_quote($v, '/') . '\b/', "COALESCE(@{$v}, FALSE)", $body) ?? $body;
                }
                return "-- Fetches already-set @var parameters up to arity {$n} (defaulting to FALSE) and returns BOOLEAN\nSELECT CAST(CASE WHEN ({$body}) THEN TRUE ELSE FALSE END AS BOOLEAN) AS result;\n";
            }

            case 'latex': {
                $body = self::compileExpr($ast, 'latex');
                $mapStr = empty($vars) ? ("() \\mapsto {$body}") : ('(' . implode(', ', $vars) . ") \\mapsto {$body}");
                return "\\documentclass{article}\n\\usepackage{amsmath, amssymb}\n\\begin{document}\n\\[\n{$mapStr}\n\\]\n\\end{document}\n";
            }

            default:
                throw new \InvalidArgumentException("Unsupported Boolean export language: '{$lang}'");
        }
    }

    public static function exportAll(BooleanNodeInterface $ast, ?array $vars = null): array
    {
        $vars = $vars ?? $ast->collectVariables();
        $out = [];
        foreach (self::SUPPORTED_LANGUAGES as $lang) {
            $out[$lang] = self::export($ast, $lang, $vars);
        }
        return $out;
    }
}

foreach ([
    'BooleanNode' => BooleanNode::class,
    'BoolLiteralNode' => BoolLiteralNode::class,
    'BoolVarNode' => BoolVarNode::class,
    'BoolUnaryOpNode' => BoolUnaryOpNode::class,
    'BoolBinaryOpNode' => BoolBinaryOpNode::class,
    'BooleanAstFactory' => BooleanAstFactory::class,
    'BooleanParser' => BooleanParser::class,
    'BooleanTruthTable' => BooleanTruthTable::class,
    'BooleanCodeExporter' => BooleanCodeExporter::class,
] as $globalAlias => $fqcn) {
    if (!class_exists($globalAlias, false)) {
        class_alias($fqcn, $globalAlias);
    }
}

// Direct Script Download endpoint: BooleanAst.php?download=1&language=csharp&expression=...
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $gateway = __DIR__ . '/App/MCP/McpGateway.php';
    if (is_file($gateway)) {
        require_once $gateway;
        \App\MCP\McpGateway::intercept(__FILE__);
    }
}

if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && isset($_GET['download'])) {
    try {
        $expr = trim((string)($_GET['expression'] ?? '(A AND B) OR NOT C'));
        $lang = (string)($_GET['language'] ?? 'php');
        $optimize = (bool)($_GET['optimize'] ?? false);
        $parsed = BooleanParser::parse($expr);
        $ast = $optimize ? $parsed['ast']->optimize() : $parsed['ast'];
        $vars = $ast->collectVariables();
        $code = BooleanCodeExporter::export($ast, $lang, $vars);
        $meta = BooleanCodeExporter::getDownloadMeta($lang);
        header('Content-Type: ' . $meta['mime'] . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $meta['filename'] . '"');
        echo $code;
    } catch (\Throwable $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Error: ' . $e->getMessage();
    }
    exit(0);
}

// REST / JSON API handler if requested directly via BooleanAst.php?api=1
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && (isset($_GET['api']) || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = (string)file_get_contents('php://input');
        $payload = json_decode($raw, true) ?: $_POST;
        $expr = trim((string)($payload['expression'] ?? ($_GET['expression'] ?? '(A AND B) OR NOT C')));
        $env = (array)($payload['environment'] ?? []);
        $lang = (string)($payload['language'] ?? ($_GET['language'] ?? 'php'));
        $optimize = (bool)($payload['optimize'] ?? false);

        $parsed = BooleanParser::parse($expr);
        $ast = $optimize ? $parsed['ast']->optimize() : $parsed['ast'];
        $vars = $ast->collectVariables();
        $tt = BooleanTruthTable::generate($ast, $vars);

        echo json_encode([
            'status' => 'success',
            'expression' => $expr,
            'variables' => $vars,
            'evaluated' => $ast->evaluate($env),
            'ast' => $ast->toArray(),
            'factory_ast' => $ast->toFactoryAstNode(),
            'truth_table' => $tt,
            'exported' => BooleanCodeExporter::export($ast, $lang, $vars),
            'download_meta' => BooleanCodeExporter::getDownloadMeta($lang),
            'all_languages' => BooleanCodeExporter::exportAll($ast, $vars),
            'openapi' => BooleanCodeExporter::toOpenApi($ast, $vars, $tt['classification']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit(0);
}

// Standalone Interactive Web Frontend UI when accessed directly in a browser
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/html; charset=utf-8');
    $defaultExpr = '(A AND B) OR NOT C';
    $initialParsed = BooleanParser::parse($defaultExpr);
    $initialAst = $initialParsed['ast'];
    $initialVars = $initialAst->collectVariables();
    $initialTt = BooleanTruthTable::generate($initialAst, $initialVars);
    $initialExports = BooleanCodeExporter::exportAll($initialAst, $initialVars);
    $initialOpenApi = BooleanCodeExporter::toOpenApi($initialAst, $initialVars, $initialTt['classification']);
    $bootData = [
        'expression' => $defaultExpr,
        'variables' => $initialVars,
        'evaluated' => true,
        'ast' => $initialAst->toArray(),
        'factory_ast' => $initialAst->toFactoryAstNode(),
        'truth_table' => $initialTt,
        'all_languages' => $initialExports,
        'openapi' => $initialOpenApi,
    ];
    $bootJson = json_encode($bootData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $bootScript = '<script id="boolean-boot-data" type="application/json">' . $bootJson . '</script>';
    $template = file_get_contents(__DIR__ . '/templates/boolean_ast.html');
    echo str_replace('<!--BOOLEAN_BOOT_DATA-->', $bootScript, $template);
    exit(0);
}
