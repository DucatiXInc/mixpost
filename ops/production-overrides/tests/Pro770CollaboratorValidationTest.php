<?php

// Run against the licensed 7.7.0 candidate and an isolated restored database.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, (string) $error);
    exit(1);
});

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Mcp\Tools\CreatePostTool;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Models\Workspace;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;

if (! app()->environment('testing') || ! str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-')) {
    throw new RuntimeException('Isolated restored MySQL required.');
}
Http::preventStrayRequests();

function collaboratorCheck(bool $passed, string $label): void
{
    if (! $passed) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}

$workspace = Workspace::withoutGlobalScopes()->findOrFail(1);
WorkspaceManager::setCurrent($workspace);
$user = $workspace->users()->wherePivotIn('role', ['admin', 'member'])->firstOrFail();
$account = Account::where('provider', 'instagram')->firstOrFail();
$versions = [[
    'account_id' => 0,
    'is_original' => true,
    'content' => [['body' => 'Isolated username validation fixture']],
    'options' => ['instagram' => ['collaborators' => ['https://instagram.com/invalid']]],
]];
$expected = 'An Instagram collaborator is a username';

DB::beginTransaction();
try {
    $count = Post::count();
    $token = $user->createToken('7.7 rehearsal fixture')['plain_text_token'];
    $request = Request::create(
        route('mixpost.api.posts.store', ['workspace' => $workspace->uuid], false),
        'POST',
        server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
        content: json_encode(['accounts' => [$account->id], 'versions' => $versions], JSON_THROW_ON_ERROR),
    );
    $response = $app->make(Kernel::class)->handle($request);
    $json = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $fieldError = $json['errors']['versions.0.options.instagram.collaborators.0'][0] ?? '';
    collaboratorCheck($response->getStatusCode() === 422 && str_starts_with($fieldError, $expected), 'REST API returns the Instagram collaborator format error');

    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    $response = (new CreatePostTool)->handle(new McpRequest([
        'workspace' => $workspace->uuid,
        'account_ids' => [$account->id],
        'versions' => $versions,
    ]));
    collaboratorCheck($response instanceof McpResponse && $response->isError()
        && str_contains($response->content()->toArray()['text'] ?? '', $expected), 'MCP create-post returns the same format error');
    collaboratorCheck(Post::count() === $count, 'invalid requests create no posts');
} finally {
    DB::rollBack();
}
