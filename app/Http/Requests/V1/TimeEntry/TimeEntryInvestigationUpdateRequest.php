<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\TimeEntry;

use Illuminate\Foundation\Http\FormRequest;

class TimeEntryInvestigationUpdateRequest extends FormRequest
{
  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'investigation_reason' => ['required', 'string', 'min:3', 'max:5000'],
      'mark_reviewed' => ['sometimes', 'boolean'],
    ];
  }
}
