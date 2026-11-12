<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Request;
use Core\Response;
use Core\ValidationException;
use Core\Validator;
use Core\View;

/**
 * Base controller with rendering and validation shortcuts.
 * Controllers stay thin: business rules belong in app/Services.
 */
abstract class Controller
{
    /**
     * Render a view inside a layout as an HTML response.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/app', int $status = 200): Response
    {
        return Response::html(View::render($view, $data, $layout), $status);
    }

    /**
     * Validate the request's input and files. On failure, redirect back to the form
     * with errors and old input; on success, return the cleaned values.
     *
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $labels
     * @return array<string, mixed>
     * @throws ValidationException
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        $input = $request->all();
        $validator = Validator::make([...$input, ...$request->files()], $rules, $labels);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors(), $input, $request->referer() ?? url($request->path()));
        }

        return $validator->validated();
    }

    /**
     * Redirect to the previous page (same-site only), or a fallback path.
     */
    protected function back(Request $request, string $fallback = '/'): Response
    {
        return Response::redirect($request->referer() ?? $fallback);
    }
}
