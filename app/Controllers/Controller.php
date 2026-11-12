<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ServiceException;
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
     * Redirect back after a business-rule failure: the message is shown under its form
     * field when it has one, otherwise as a flash; submitted input is kept.
     */
    protected function failed(Request $request, ServiceException $e, string $fallback = '/'): Response
    {
        $response = $this->back($request, $fallback)->withInput($request->all());

        return $e->field() !== ''
            ? $response->withErrors([$e->field() => [$e->getMessage()]])->with('danger', 'Please correct the highlighted fields and try again.')
            : $response->with('danger', $e->getMessage());
    }

    /**
     * Current page number from ?page= (minimum 1).
     */
    protected function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    /**
     * A trimmed string from the query string ('' when absent or not a string).
     */
    protected function queryString(Request $request, string $key): string
    {
        $value = $request->query($key, '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Redirect to the previous page (same-site only), or a fallback path.
     */
    protected function back(Request $request, string $fallback = '/'): Response
    {
        return Response::redirect($request->referer() ?? $fallback);
    }
}
