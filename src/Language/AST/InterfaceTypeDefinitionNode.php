<?php

declare(strict_types=1);

namespace GraphQL\Language\AST;

class InterfaceTypeDefinitionNode extends Node implements TypeDefinitionNode
{
    /** @var string */
    public $kind = NodeKind::INTERFACE_TYPE_DEFINITION;

    /** @var NameNode */
    public $name;

    /** @var NodeList<DirectiveNode>|null */
    public $directives;

    /** @var NodeList<FieldDefinitionNode>|null */
    public $fields;

    /** @var StringValueNode|null */
    public $description;
}
