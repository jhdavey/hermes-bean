<?php

namespace App\Observers;

use App\Services\DashboardChangeNotifier;
use Illuminate\Database\Eloquent\Model;

class DashboardResourceObserver
{
    public function created(Model $model): void
    {
        app(DashboardChangeNotifier::class)->modelChanged($model, 'created', [
            'title' => $model->getAttribute('title'),
        ]);
    }

    public function updated(Model $model): void
    {
        $changes = collect($model->getChanges())
            ->except(['updated_at'])
            ->keys()
            ->values()
            ->all();

        if ($changes === []) {
            return;
        }

        app(DashboardChangeNotifier::class)->modelChanged($model, 'updated', [
            'title' => $model->getAttribute('title'),
            'changed_fields' => $changes,
        ]);
    }

    public function deleted(Model $model): void
    {
        app(DashboardChangeNotifier::class)->modelChanged($model, 'deleted', [
            'title' => $model->getAttribute('title'),
        ]);
    }
}
