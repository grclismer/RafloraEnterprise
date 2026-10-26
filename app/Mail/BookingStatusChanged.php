<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingStatusChanged extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public ?Payment $payment;
    public string $status;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, ?Payment $payment, string $status)
    {
        $this->booking = $booking;
        $this->payment = $payment;
        $this->status = $status;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = match ($this->status) {
            'declined' => 'Your booking request was declined',
            'downpayment_received' => 'Downpayment Received',
            'quotation_sent' => 'Your quotation is ready',
            'payment_pending' => 'Booking accepted — payment is pending',
            'note_sent' => 'A new note from Raflora Enterprises',
            'proposal_uploaded' => 'A new proposal for your booking',
            default => \App\Services\ClientStatusNotifier::content($this->status)[0],
        };

        return $this->subject($subject)
            ->view('emails.booking-status')
            ->with([
                'booking' => $this->booking,
                'payment' => $this->payment,
                'status' => $this->status,
            ]);
    }
}
