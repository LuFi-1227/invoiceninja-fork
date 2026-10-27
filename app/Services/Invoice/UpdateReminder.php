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

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\RecurringInvoice;
use App\Services\AbstractService;
use Carbon\Carbon;

class UpdateReminder extends AbstractService
{
    public function __construct(public Invoice $invoice, public mixed $settings = null) {}

    /* We only support setting reminders based on the due date, not the partial due date */
    public function run()
    {
        if (! $this->settings) {
            $this->settings = $this->invoice->client->getMergedSettings();
        }

        if (! $this->invoice->isPayable() || $this->invoice->status_id == Invoice::STATUS_DRAFT) {
            $this->invoice->next_send_date = null;
            $this->invoice->saveQuietly();

            return $this->invoice; //exit early
        }

        if ($this->invoice->next_send_date) {
            $this->invoice->next_send_date = null;
        }

        $offset = $this->invoice->client->timezone_offset();

        $date_collection = collect();

        if (is_null($this->invoice->reminder1_sent)
           && $this->settings->schedule_reminder1 == 'after_invoice_date') {
            $reminder_date = Carbon::parse($this->invoice->date)->startOfDay()->addDays((int) $this->settings->num_days_reminder1)->addSeconds($offset);

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder1_sent)
           && ($this->invoice->partial_due_date || $this->invoice->due_date)
           && $this->settings->schedule_reminder1 == 'before_due_date') {
            $partial_or_due_date = ($this->invoice->partial > 0 && isset($this->invoice->partial_due_date)) ? $this->invoice->partial_due_date : $this->invoice->due_date;
            $reminder_date = Carbon::parse($partial_or_due_date)->startOfDay()->subDays((int) $this->settings->num_days_reminder1)->addSeconds($offset);
            // nlog("1. {$reminder_date->format('Y-m-d')}");

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder1_sent)
           && ($this->invoice->partial_due_date || $this->invoice->due_date)
           && $this->settings->schedule_reminder1 == 'after_due_date') {

            $partial_or_due_date = ($this->invoice->partial > 0 && isset($this->invoice->partial_due_date)) ? $this->invoice->partial_due_date : $this->invoice->due_date;
            $reminder_date = Carbon::parse($partial_or_due_date)->startOfDay()->addDays((int) $this->settings->num_days_reminder1)->addSeconds($offset);
            // nlog("2. {$reminder_date->format('Y-m-d')}");

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder2_sent)
           && $this->settings->schedule_reminder2 == 'after_invoice_date') {
            $reminder_date = Carbon::parse($this->invoice->date)->startOfDay()->addDays((int) $this->settings->num_days_reminder2)->addSeconds($offset);

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder2_sent)
           && ($this->invoice->partial_due_date || $this->invoice->due_date)
           && $this->settings->schedule_reminder2 == 'before_due_date') {

            $partial_or_due_date = ($this->invoice->partial > 0 && isset($this->invoice->partial_due_date)) ? $this->invoice->partial_due_date : $this->invoice->due_date;
            $reminder_date = Carbon::parse($partial_or_due_date)->startOfDay()->subDays((int) $this->settings->num_days_reminder2)->addSeconds($offset);
            // nlog("3. {$reminder_date->format('Y-m-d')}");

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder2_sent)
           && ($this->invoice->partial_due_date || $this->invoice->due_date)
           && $this->settings->schedule_reminder2 == 'after_due_date') {

            $partial_or_due_date = ($this->invoice->partial > 0 && isset($this->invoice->partial_due_date)) ? $this->invoice->partial_due_date : $this->invoice->due_date;
            $reminder_date = Carbon::parse($partial_or_due_date)->startOfDay()->addDays((int) $this->settings->num_days_reminder2)->addSeconds($offset);
            // nlog("4. {$reminder_date->format('Y-m-d')}");

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder3_sent)
           && $this->settings->schedule_reminder3 == 'after_invoice_date') {
            $reminder_date = Carbon::parse($this->invoice->date)->startOfDay()->addDays((int) $this->settings->num_days_reminder3)->addSeconds($offset);

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder3_sent)
           && ($this->invoice->partial_due_date || $this->invoice->due_date)
           && $this->settings->schedule_reminder3 == 'before_due_date') {

            $partial_or_due_date = ($this->invoice->partial > 0 && isset($this->invoice->partial_due_date)) ? $this->invoice->partial_due_date : $this->invoice->due_date;
            $reminder_date = Carbon::parse($partial_or_due_date)->startOfDay()->subDays((int) $this->settings->num_days_reminder3)->addSeconds($offset);
            // nlog("5. {$reminder_date->format('Y-m-d')}");

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        if (is_null($this->invoice->reminder3_sent)
           && ($this->invoice->partial_due_date || $this->invoice->due_date)
           && $this->settings->schedule_reminder3 == 'after_due_date') {

            $partial_or_due_date = ($this->invoice->partial > 0 && isset($this->invoice->partial_due_date)) ? $this->invoice->partial_due_date : $this->invoice->due_date;
            $reminder_date = Carbon::parse($partial_or_due_date)->startOfDay()->addDays((int) $this->settings->num_days_reminder3)->addSeconds($offset);
            // nlog("6. {$reminder_date->format('Y-m-d')}");

            if ($reminder_date->gte(now()->startOfDay())) {
                $date_collection->push($reminder_date);
            }
        }

        // Post-due (endless) reminders:
        // Schedule: Every 2 days (3 notices = days 2, 4, 6 overdue), then weekly (days 13, 20, 27 overdue) up to 30 days overdue.
        if ($this->settings->enable_reminder_endless
           && ($this->invoice->partial_due_date || $this->invoice->due_date)
           && ($this->invoice->reminder3_sent || $this->settings->schedule_reminder3 == "" || !$this->settings->enable_reminder3 || Carbon::parse($this->invoice->partial_due_date ?: $this->invoice->due_date)->startOfDay()->lt(now()->startOfDay()))
        ) {
            $partial_or_due_date = ($this->invoice->partial > 0 && isset($this->invoice->partial_due_date)) ? $this->invoice->partial_due_date : $this->invoice->due_date;
            $dueCarbon = Carbon::parse($partial_or_due_date)->startOfDay();

            $overdueScheduleDays = [2, 4, 6, 13, 20, 27];
            foreach ($overdueScheduleDays as $days) {
                $candidate = $dueCarbon->copy()->addDays($days)->addSeconds($offset);
                if ($candidate->gte(now()->startOfDay())) {
                    if (empty($this->invoice->reminder_last_sent) || Carbon::parse($this->invoice->reminder_last_sent)->startOfDay()->lt($candidate->copy()->startOfDay())) {
                        $date_collection->push($candidate);
                        break;
                    }
                }
            }
        }

        if ($date_collection->count() >= 1) {
            $earliest = $date_collection->sort()->first();
            if ($earliest->isSameDay(now()) || $earliest->lt(now())) {
                $this->invoice->next_send_date = now();
            } else {
                $this->invoice->next_send_date = $earliest;
            }
        } else {
            $this->invoice->next_send_date = null;
        }

        return $this->invoice;
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
