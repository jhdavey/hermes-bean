<?php

namespace Tests\Feature;

use App\Console\Commands\RunBeanProductionSmokeSuite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class RunBeanProductionSmokeSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_limit_copy_counts_as_smoke_failure(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'containsFailureCopy');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's AI usage limit.",
        ));
        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's external lookup usage limit.",
        ));
        $this->assertFalse($method->invoke(
            $command,
            'Done - I added the three events to your calendar.',
        ));
    }

    public function test_smoke_quality_checks_flag_weak_responses(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'assistantQualityFailures');
        $method->setAccessible(true);

        $this->assertContains('missing_write_confirmation', $method->invoke(
            $command,
            'REQ-001: Create a task to review insurance paperwork tomorrow morning.',
            'I can help with that.',
        ));
        $this->assertContains('missing_weather_details', $method->invoke(
            $command,
            'REQ-061: Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
            'Tomorrow should be fine.',
        ));
        $this->assertContains('missing_place_details', $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'I found one nearby.',
        ));
        $this->assertContains('wrong_wawa_32820', $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'The nearest Wawa I found near 32820 is Wawa at 6500 Lee Vista Boulevard, Orlando, FL 32822, USA.',
        ));
        $this->assertContains('wrong_place_32820', $method->invoke(
            $command,
            'REQ-074: Find the nearest Starbucks to 32820 and tell me the address quickly.',
            'The nearest Starbucks to 32820 is in Ohio. The address is 123 Main St, Ohio.',
        ));
        $this->assertContains('wrong_starbucks_32820', $method->invoke(
            $command,
            'REQ-074: Find the nearest Starbucks to 32820 and tell me the address quickly.',
            'The nearest Starbucks I found near 32820 is Starbucks at 1 Coffee Rd, Orlando, FL.',
        ));
        $this->assertContains('wrong_home_depot_32820', $method->invoke(
            $command,
            'REQ-076: Find the nearest Home Depot to 32820 and tell me the address quickly.',
            'The nearest Home Depot I found near 32820 is Home Depot at 655 East Colonial Drive, Orlando, FL.',
        ));
        $this->assertContains('missing_memory_confirmation', $method->invoke(
            $command,
            'REQ-081: Remember that I prefer short practical answers unless I ask for detail, then tell me what you saved.',
            'That makes sense.',
        ));
        $this->assertContains('missing_day_context', $method->invoke(
            $command,
            'REQ-091: What do I have coming up today, and if there is empty time after 5pm, suggest a simple plan.',
            'Sounds good.',
        ));
        $this->assertContains('wrong_request_history_dr_chen', $method->invoke(
            $command,
            'REQ-097: What request did I make about Dr Chen Cardio earlier in this smoke run?',
            'Here is what I found in your request history: REQ-073: Find a nearby Wawa around 32820.',
        ));
        $this->assertContains('wrong_request_history_egg_protein', $method->invoke(
            $command,
            'REQ-099: What was my earlier request about Egg Protein Note, if any? If there was none, say so clearly.',
            'Here is what I found in your request history: REQ-053: Create a project follow-up workflow for the budget cleanup.',
        ));
        $this->assertContains('wrong_memory_recall_errand_updates', $method->invoke(
            $command,
            'What did you just save about errand updates?',
            'I saved that preference for you.',
        ));
    }

    public function test_smoke_quality_checks_accept_useful_responses(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'assistantQualityFailures');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(
            $command,
            'REQ-011: Add three calendar events: 7/9 Dr Chen Cardio at 100 N Dean Rd at 3pm, 7/15 Ventura at 6pm, and 7/19 Azalea Lane at 2pm.',
            'Done - I added Dr Chen Cardio to your calendar for Jul 9, 3:00 PM, I added Ventura to your calendar for Jul 15, 6:00 PM, and I added Azalea Lane to your calendar for Jul 19, 2:00 PM.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-061: Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
            'Tomorrow in Orlando should be stormy. High 94°F, low 76°F, with precipitation possible.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'The nearest Wawa I found near 32820 is Wawa at 16959 E Colonial Dr, Orlando, FL 32820, USA about 1.4 miles away.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-076: Find the nearest Home Depot to 32820 and tell me the address quickly.',
            'The nearest Home Depot I found near 32820 is The Home Depot at 350 N Alafaya Trail, Orlando, FL 32828, USA.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-074: Find the nearest Starbucks to 32820 and tell me the address quickly.',
            'The nearest Starbucks I found near 32820 is Starbucks Coffee Company at 321 Avalon Park S Blvd, Orlando, FL 32828, USA.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-097: What request did I make about Dr Chen Cardio earlier in this smoke run?',
            'You asked: REQ-011: Add three calendar events: 7/9 Dr Chen Cardio at 100 N Dean Rd at 3pm.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-099: What was my earlier request about Egg Protein Note, if any? If there was none, say so clearly.',
            'I checked your request history, but I did not find anything matching that.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'What did you just save about errand updates?',
            'I saved that you prefer concise status updates for errands.',
        ));
    }

    public function test_kpi_prompt_suite_has_thirty_cases_and_expected_classes(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $prompts = new ReflectionMethod($command, 'kpiPrompts');
        $prompts->setAccessible(true);
        $classifier = new ReflectionMethod($command, 'promptBenchmarkClass');
        $classifier->setAccessible(true);

        $cases = $prompts->invoke($command);

        $this->assertCount(30, $cases);
        $this->assertSame('general_question', $classifier->invoke($command, 'KPI-001: Can you create calendar events for me?'));
        $this->assertSame('app_context_lookup', $classifier->invoke($command, 'KPI-006: What do I have coming up today?'));
        $this->assertSame('simple_crud', $classifier->invoke($command, 'KPI-011: Create a calendar event called KPI Focus Block tomorrow at 9am for 30 minutes.'));
        $this->assertSame('complex_crud', $classifier->invoke($command, 'KPI-016: Create a calendar event tomorrow at 10am called KPI Planning, add a task to prepare the agenda, and remind me 30 minutes before.'));
        $this->assertSame('external_lookup', $classifier->invoke($command, 'KPI-021: Find the weather for tomorrow in Orlando and tell me if an evening walk makes sense.'));
    }

    public function test_complex_kpi_prompt_suite_has_thirty_new_cases_and_expected_classes(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $prompts = new ReflectionMethod($command, 'kpiComplexPrompts');
        $prompts->setAccessible(true);
        $classifier = new ReflectionMethod($command, 'promptBenchmarkClass');
        $classifier->setAccessible(true);

        $cases = $prompts->invoke($command);

        $this->assertCount(30, $cases);
        $this->assertSame('complex_crud', $classifier->invoke($command, 'KPI-C001: Set up tomorrow morning: calendar focus block at 8:30am for 45 minutes, task to draft the outline, reminder 20 minutes before, and a note called Morning Outline.'));
        $this->assertSame('app_context_lookup', $classifier->invoke($command, 'KPI-C013: What request did I make about KPI Dentist earlier in this smoke run?'));
        $this->assertSame('app_context_lookup', $classifier->invoke($command, 'KPI-C014: What do I have coming up today?'));
        $this->assertSame('external_lookup', $classifier->invoke($command, 'KPI-C016: Find the weather for tomorrow in Orlando and tell me if an evening walk makes sense.'));
        $this->assertSame('complex_crud', $classifier->invoke($command, 'KPI-C027: Create a note called Budget Review Notes with three practical bullets, pin it, and add a reminder tomorrow at 4pm to review it.'));
    }

    public function test_kpi_summary_requires_ninety_eight_percent_for_success_and_progress(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $summary = new ReflectionMethod($command, 'kpiSummary');
        $summary->setAccessible(true);
        $meetsTargets = new ReflectionMethod($command, 'kpiSummaryMeetsTargets');
        $meetsTargets->setAccessible(true);

        $passing = [];
        for ($index = 0; $index < 50; $index++) {
            $passing[] = [
                'status' => 'completed',
                'first_response_ms' => 100,
                'duration_ms' => 900,
                'completion_target_ms' => 3000,
                'dashboard_freshness_ms' => 0,
                'quality_failures' => [],
                'kpi_failures' => [],
                'assistant' => 'Done - useful response.',
            ];
        }

        $allPassingSummary = $summary->invoke($command, $passing);
        $this->assertTrue($meetsTargets->invoke($command, $allPassingSummary));
        $this->assertSame(1.0, $allPassingSummary['action_success_without_user_correction']['rate']);
        $this->assertSame(1.0, $allPassingSummary['progress_transparency_accuracy']['rate']);

        $oneFailure = $passing;
        $oneFailure[0]['quality_failures'] = ['missing_write_confirmation'];
        $oneFailure[0]['kpi_failures'] = ['work_item_not_completed:crud-plan-1-0'];
        $oneFailureSummary = $summary->invoke($command, $oneFailure);

        $this->assertTrue($meetsTargets->invoke($command, $oneFailureSummary));
        $this->assertSame(0.98, $oneFailureSummary['action_success_without_user_correction']['rate']);
        $this->assertSame(0.98, $oneFailureSummary['progress_transparency_accuracy']['rate']);

        $twoFailures = $oneFailure;
        $twoFailures[1]['quality_failures'] = ['missing_write_confirmation'];
        $twoFailures[1]['kpi_failures'] = ['runtime_progress_missing'];
        $twoFailureSummary = $summary->invoke($command, $twoFailures);

        $this->assertFalse($meetsTargets->invoke($command, $twoFailureSummary));
        $this->assertSame(0.96, $twoFailureSummary['action_success_without_user_correction']['rate']);
        $this->assertSame(0.96, $twoFailureSummary['progress_transparency_accuracy']['rate']);
    }
}
