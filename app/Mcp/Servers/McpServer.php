<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateRecipeTool;
use App\Mcp\Tools\GetRecipeTool;
use App\Mcp\Tools\ListRecipesTool;
use App\Mcp\Tools\RenderRecipeTool;
use App\Mcp\Tools\UpdateRecipeMarkupTool;
use App\Mcp\Tools\UpdateRecipeSettingsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('LaraPaper MCP Server')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
    This MCP server manages LaraPaper recipe plugins for the authenticated user.

    Typical workflow:
    1. Use list-recipes or get-recipe to inspect existing recipes by ID.
    2. Use render-recipe to render markup and inspect the resulting HTML.
    3. Use update-recipe-markup or update-recipe-settings to fix issues.
    4. Re-run render-recipe until it succeeds.
    5. Use create-recipe when a new recipe is needed. It returns the recipe ID and browser URL.

    Recipes use Blade markup by default. Pass markup_language: liquid when writing Liquid templates. When MULTIUSER_REGISTRATION_ENABLED is on, Blade markup can still be saved, but render-recipe does not compile it unless INSECURE_BLADE_ALLOW_PHP_EXECUTION is enabled.

    Recipe tools identify recipes by numeric database ID, not UUID.

    Only recipe plugins (not native plugin types) are accessible through these tools.
    MARKDOWN)]
class McpServer extends Server
{
    protected array $tools = [
        ListRecipesTool::class,
        GetRecipeTool::class,
        CreateRecipeTool::class,
        UpdateRecipeMarkupTool::class,
        UpdateRecipeSettingsTool::class,
        RenderRecipeTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
