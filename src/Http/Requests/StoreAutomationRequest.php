<?php

namespace Goldnead\StatamicAutomations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationRequest extends FormRequest
{
    use ScopesUniquenessToBrand;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (! method_exists($user, 'can') || $user->can('create automations'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'handle' => [
                'nullable', 'string', 'max:255',
                $this->brandScoped(Rule::unique('automations', 'handle')),
            ],
            'description' => ['nullable', 'string'],

            'nodes' => ['array'],
            'nodes.*.node_key' => ['required', 'string', 'max:255'],
            'nodes.*.type' => ['required', 'string', 'max:255'],
            'nodes.*.label' => ['nullable', 'string'],
            'nodes.*.position_x' => ['nullable', 'integer'],
            'nodes.*.position_y' => ['nullable', 'integer'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.disabled' => ['nullable', 'boolean'],

            'edges' => ['array'],
            'edges.*.from_node_key' => ['required', 'string'],
            'edges.*.from_output' => ['nullable', 'string'],
            'edges.*.to_node_key' => ['required', 'string'],
            'edges.*.to_input' => ['nullable', 'string'],
        ];
    }
}
