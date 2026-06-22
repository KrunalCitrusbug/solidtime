<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Task;

use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;
use Korridor\LaravelModelValidationRules\Rules\UniqueEloquent;

/**
 * @property Organization $organization Organization from model binding
 */
class TaskStoreRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:255',
                UniqueEloquent::make(Task::class, 'name', function (Builder $builder): Builder {
                    /** @var Builder<Task> $builder */
                    return $builder->where('project_id', '=', $this->input('project_id'));
                })->withCustomTranslation('validation.task_name_already_exists'),
            ],
            'project_id' => [
                'required',
                ExistsEloquent::make(Project::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Project> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            // Estimated time in seconds
            'estimated_time' => [
                'nullable',
                'integer',
                'min:0',
                'max:2147483647',
            ],
            // Whether the task is public (anyone with project access) or private
            'is_public' => [
                'nullable',
                'boolean',
            ],
            // Members granted access to the task (relevant for private tasks)
            'member_ids' => [
                'nullable',
                'array',
            ],
            'member_ids.*' => [
                ExistsEloquent::make(Member::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Member> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
        ];
    }

    public function getEstimatedTime(): ?int
    {
        $input = $this->input('estimated_time');

        return $input !== null && $input !== 0 ? (int) $this->input('estimated_time') : null;
    }
}
