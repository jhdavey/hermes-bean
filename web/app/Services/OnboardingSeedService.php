<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OnboardingSeedService
{
    public function ensureForUser(User $user): void
    {
        $hasTasks = $user->tasks()->exists();
        $hasReminders = $user->reminders()->exists();
        $hasCalendarEvents = $user->calendarEvents()->exists();

        if ($hasTasks && $hasReminders && $hasCalendarEvents) {
            return;
        }

        DB::transaction(function () use ($user, $hasTasks, $hasReminders, $hasCalendarEvents): void {
            $today = Carbon::today();
            $tomorrow = Carbon::tomorrow();

            $session = ConversationSession::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'title' => 'Welcome to Bean',
                ],
                [
                    'status' => 'active',
                    'runtime_mode' => 'onboarding_seed',
                    'last_activity_at' => now(),
                    'metadata' => ['seeded_from' => 'hermes_bean_onboarding_v1'],
                ]
            );

            if (! $hasTasks) {
                Task::create([
                    'user_id' => $user->id,
                    'conversation_session_id' => $session->id,
                    'title' => 'Plan launch',
                    'type' => 'todo',
                    'status' => 'open',
                    'due_at' => $today->copy()->setTime(11, 0),
                    'metadata' => ['seeded_from' => 'hermes_bean_onboarding_v1'],
                ]);
            }

            if (! $hasReminders) {
                Reminder::create([
                    'user_id' => $user->id,
                    'conversation_session_id' => $session->id,
                    'title' => 'Stand up',
                    'remind_at' => $today->copy()->setTime(9, 0),
                    'status' => 'pending',
                    'metadata' => ['seeded_from' => 'hermes_bean_onboarding_v1'],
                ]);
            }

            if (! $hasCalendarEvents) {
                CalendarEvent::create([
                    'user_id' => $user->id,
                    'conversation_session_id' => $session->id,
                    'title' => 'Design review',
                    'description' => 'Seeded example calendar event for verifying the Calendar surface.',
                    'starts_at' => $today->copy()->setTime(14, 30),
                    'ends_at' => $today->copy()->setTime(15, 0),
                    'status' => 'scheduled',
                    'metadata' => ['seeded_from' => 'hermes_bean_onboarding_v1'],
                ]);

                CalendarEvent::create([
                    'user_id' => $user->id,
                    'conversation_session_id' => $session->id,
                    'title' => 'Plan dinner',
                    'description' => 'Seeded next-day example so the two-day calendar has visible content.',
                    'starts_at' => $tomorrow->copy()->setTime(18, 0),
                    'ends_at' => $tomorrow->copy()->setTime(19, 0),
                    'status' => 'scheduled',
                    'metadata' => ['seeded_from' => 'hermes_bean_onboarding_v1'],
                ]);
            }

            ActivityEvent::create([
                'user_id' => $user->id,
                'conversation_session_id' => $session->id,
                'event_type' => 'assistant.onboarding.seeded',
                'payload' => [
                    'tasks' => $hasTasks ? 0 : 1,
                    'reminders' => $hasReminders ? 0 : 1,
                    'calendar_events' => $hasCalendarEvents ? 0 : 2,
                ],
            ]);
        });
    }
}
