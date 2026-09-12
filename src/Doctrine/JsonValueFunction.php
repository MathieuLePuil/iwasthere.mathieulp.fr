<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * JSON_VALUE(champ, '$.chemin') en DQL → JSON_UNQUOTE(JSON_EXTRACT(champ, chemin)).
 *
 * Les notifications gardent leurs références (friendId, participationId,
 * eventId…) dans une colonne JSON ; sans cette fonction, les retrouver
 * obligeait à charger toutes les notifications d'un type et à filtrer en PHP.
 */
final class JsonValueFunction extends FunctionNode
{
    private Node|string $field;
    private Node $path;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->path = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'JSON_UNQUOTE(JSON_EXTRACT(%s, %s))',
            $this->field->dispatch($sqlWalker),
            $this->path->dispatch($sqlWalker),
        );
    }
}
