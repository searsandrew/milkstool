<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class SourceReviews extends Command
{
    protected $signature = 'milkstool:source-reviews {--customer= : Optional customer internal ID} {--json : JSON output} {--limit=100 : Maximum records to show}';

    protected $description = 'List preserved source discrepancies requiring staff review; never exposed through customer APIs';

    public function handle(): int
    {
        $input = ['customer' => $this->option('customer'), 'limit' => $this->option('limit')];
        if (Validator::make($input, ['customer' => ['nullable', 'integer', 'min:1'], 'limit' => ['required', 'integer', 'between:1,1000']])->fails()) {
            $this->error('Customer must be a positive ID and limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $query = Transaction::query()->withoutGlobalScope('present_in_netsuite')->whereNotNull('source_review_required_at')
            ->when($input['customer'] !== null, fn ($query) => $query->where('company_id', (int) $input['customer']));
        $total = (clone $query)->count();
        $records = $query->orderBy('source_review_required_at')->orderBy('id')->limit((int) $input['limit'])
            ->get(['id', 'company_id', 'type', 'number', 'source_missing_at', 'source_review_required_at', 'source_review_reason', 'source_review_context']);
        if ($this->option('json')) {
            $this->line(json_encode(['total' => $total, 'records' => $records], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info($total.' records require staff review.');
            $this->table(['ID', 'Customer', 'Type', 'Number', 'Missing since', 'Review reason'], $records->map(fn ($record) => [
                $record->id, $record->company_id, $record->type, $record->number, $record->source_missing_at?->toIso8601String() ?? 'Returned to source', $record->source_review_reason,
            ])->all());
        }

        return self::SUCCESS;
    }
}
