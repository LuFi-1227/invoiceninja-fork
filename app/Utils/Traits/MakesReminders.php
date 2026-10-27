<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Utils\Traits;

use App\Models\RecurringInvoice;
use Illuminate\Support\Carbon;

/**
 * Class MakesReminders.
 *
 */
trait MakesReminders
{
    /**
     * @param string $schedule_reminder
     * @param string $num_days_reminder
     * @return ?bool
     */
    public function inReminderWindow($schedule_reminder, $num_days_reminder)
    {
        /** @var \App\Models\Invoice | \App\Models\Quote | \App\Models\RecurringInvoice  | \App\Models\Credit $this **/
        $offset = $this->client->timezone_offset();
        $entity_send_time = $this->client->getSetting('entity_send_time');

        $today = $entity_send_time === 0
            ? now()->startOfDay()->toDateString()
            : now()->setTimezone($this->client->timezone()->name)->startOfDay()->toDateString();

        switch ($schedule_reminder) {
            case 'after_invoice_date':
                // return Carbon::parse($this->date)->addDays((int)$num_days_reminder)->startOfDay()->addSeconds($offset)->isSameDay(Carbon::now());

                return Carbon::parse($this->date)
                            ->addDays((int) $num_days_reminder)
                            ->startOfDay()
                            ->toDateString() <= $today;
                // === ($entity_send_time === 0 ? now()->startOfDay()->toDateString() : now()->setTimezone($this->client->timezone()->name)->startOfDay()->toDateString());

            case 'before_due_date':
                $partial_or_due_date = ($this->partial > 0 && isset($this->partial_due_date)) ? $this->partial_due_date : $this->due_date;
                // return Carbon::parse($partial_or_due_date)->subDays((int)$num_days_reminder)->startOfDay()->addSeconds($offset)->isSameDay(Carbon::now());

                return Carbon::parse($partial_or_due_date)
                            ->subDays((int) $num_days_reminder)
                            ->startOfDay()
                            ->toDateString() <= $today;
                // === ($entity_send_time === 0 ? now()->startOfDay()->toDateString() : now()->setTimezone($this->client->timezone()->name)->startOfDay()->toDateString());


            case 'after_due_date':
                $partial_or_due_date = ($this->partial > 0 && isset($this->partial_due_date)) ? $this->partial_due_date : $this->due_date;
                // return Carbon::parse($partial_or_due_date)->addDays((int)$num_days_reminder)->startOfDay()->addSeconds($offset)->isSameDay(Carbon::now());

                return Carbon::parse($partial_or_due_date)
                            ->addDays((int) $num_days_reminder)
                            ->startOfDay()
                            ->toDateString() <= $today;
                // === ($entity_send_time === 0 ? now()->startOfDay()->toDateString() : now()->setTimezone($this->client->timezone()->name)->startOfDay()->toDateString());

            case 'after_quote_date':

                return Carbon::parse($this->date)
                            ->addDays((int) $num_days_reminder)
                            ->startOfDay()
                            ->toDateString() <= $today;
                // === ($entity_send_time === 0 ? now()->startOfDay()->toDateString() : now()->setTimezone($this->client->timezone()->name)->startOfDay()->toDateString());

            case 'after_valid_until_date':

                return Carbon::parse($this->due_date)
                            ->addDays((int) $num_days_reminder)
                            ->startOfDay()
                            ->toDateString() <= $today;
                // === ($entity_send_time === 0 ? now()->startOfDay()->toDateString() : now()->setTimezone($this->client->timezone()->name)->startOfDay()->toDateString());

            case 'before_valid_until_date':

                return Carbon::parse($this->due_date)
                            ->subDays((int) $num_days_reminder)
                            ->startOfDay()
                            ->toDateString() <= $today;
                // === ($entity_send_time === 0 ? now()->startOfDay()->toDateString() : now()->setTimezone($this->client->timezone()->name)->startOfDay()->toDateString());

            default:
                return null;
        }
    }

    public function calculateTemplate(string $entity_string): string
    {

        /** @var \App\Models\Invoice | \App\Models\Quote | \App\Models\RecurringInvoice  | \App\Models\Credit $this **/
        $client = $this->client;

        if ($entity_string != 'invoice') {
            return $entity_string;
        }

        if ($this->inReminderWindow(
            $client->getSetting('schedule_reminder1'),
            $client->getSetting('num_days_reminder1')
        ) && ! $this->reminder1_sent) {
            return 'reminder1';
        } elseif ($this->inReminderWindow(
            $client->getSetting('schedule_reminder2'),
            $client->getSetting('num_days_reminder2')
        ) && ! $this->reminder2_sent) {
            return 'reminder2';
        } elseif ($this->inReminderWindow(
            $client->getSetting('schedule_reminder3'),
            $client->getSetting('num_days_reminder3')
        ) && ! $this->reminder3_sent) {
            return 'reminder3';
        } elseif ($this->checkEndlessReminder(
            $this->reminder_last_sent,
            $client->getSetting('endless_reminder_frequency_id')
        )) {
            return 'endless_reminder';
        } else {
            return $entity_string;
        }

    }

    private function checkEndlessReminder($last_sent_date, $endless_reminder_frequency_id): bool
    {
        // Don't send more than one reminder on the same day
        if ($last_sent_date && Carbon::parse($last_sent_date)->startOfDay()->eq(Carbon::now()->startOfDay())) {
            return false;
        }

        $dueDate = $this->partial_due_date ?: $this->due_date;
        if (!$dueDate) {
            return false;
        }

        $dueCarbon = Carbon::parse($dueDate)->startOfDay();
        $today = Carbon::now()->startOfDay();

        // Must be overdue
        if ($dueCarbon->gte($today)) {
            return false;
        }

        $daysOverdue = (int) $dueCarbon->diffInDays($today, false);

        // Cap at 30 days overdue: do not send reminders after 30 days of overdue
        if ($daysOverdue > 30 || $daysOverdue < 2) {
            return false;
        }

        // Post-due schedule:
        // Phase 1 (days 2 to 6): every 2 days (target days: 2, 4, 6)
        // Phase 2 (days 7 to 30): weekly (target days: 13, 20, 27)
        $scheduledDays = [2, 4, 6, 13, 20, 27];

        if (in_array($daysOverdue, $scheduledDays, true)) {
            return true;
        }

        // Fallback: If a scheduled day was missed (e.g. server down on exact day),
        // allow firing if enough time has passed since last sent:
        // - In Phase 1 (<= 6 days): at least 2 days since last sent
        // - In Phase 2 (7 to 30 days): at least 7 days since last sent
        if (!empty($last_sent_date)) {
            $daysSinceLastSent = Carbon::parse($last_sent_date)->startOfDay()->diffInDays($today);
            if ($daysOverdue <= 6 && $daysSinceLastSent >= 2) {
                return true;
            }
            if ($daysOverdue > 6 && $daysSinceLastSent >= 7) {
                return true;
            }
        } elseif ($daysOverdue >= 2) {
            return true;
        }

        return false;
    }

    private function addTimeInterval($date, $endless_reminder_frequency_id): ?Carbon
    {
        if (! $date) {
            return null;
        }

        switch ($endless_reminder_frequency_id) {
            case RecurringInvoice::FREQUENCY_DAILY:
                return Carbon::parse($date)->addDay()->startOfDay();
            case RecurringInvoice::FREQUENCY_WEEKLY:
                return Carbon::parse($date)->addWeek()->startOfDay();
            case RecurringInvoice::FREQUENCY_TWO_WEEKS:
                return Carbon::parse($date)->addWeeks(2)->startOfDay();
            case RecurringInvoice::FREQUENCY_FOUR_WEEKS:
                return Carbon::parse($date)->addWeeks(4)->startOfDay();
            case RecurringInvoice::FREQUENCY_MONTHLY:
                return Carbon::parse($date)->addMonthNoOverflow()->startOfDay();
            case RecurringInvoice::FREQUENCY_TWO_MONTHS:
                return Carbon::parse($date)->addMonthsNoOverflow(2)->startOfDay();
            case RecurringInvoice::FREQUENCY_THREE_MONTHS:
                return Carbon::parse($date)->addMonthsNoOverflow(3)->startOfDay();
            case RecurringInvoice::FREQUENCY_FOUR_MONTHS:
                return Carbon::parse($date)->addMonthsNoOverflow(4)->startOfDay();
            case RecurringInvoice::FREQUENCY_SIX_MONTHS:
                return Carbon::parse($date)->addMonthsNoOverflow(6)->startOfDay();
            case RecurringInvoice::FREQUENCY_ANNUALLY:
                return Carbon::parse($date)->addYear()->startOfDay();
            case RecurringInvoice::FREQUENCY_TWO_YEARS:
                return Carbon::parse($date)->addYears(2)->startOfDay();
            case RecurringInvoice::FREQUENCY_THREE_YEARS:
                return Carbon::parse($date)->addYears(3)->startOfDay();
            default:
                return null;
        }
    }
}
