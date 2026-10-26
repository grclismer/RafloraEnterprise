<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Booking Status Update</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222;">
    <div style="max-width:600px;margin:30px auto;padding:24px;border:1px solid #f1f1f1;border-radius:8px;">
        <h2 style="color:#2d0b6b">Raflora Enterprises</h2>
        <p>Hello {{ $booking->client->full_name }},</p>

        @if($status === 'declined')
            <p>We reviewed your booking request for <strong>{{ $booking->event_type }}</strong> on <strong>{{ $booking->event_date->format('F j, Y') }}</strong> and unfortunately we had to decline it.</p>
            @if(!empty($booking->cancellation_reason))
                <p><strong>Reason:</strong> {{ $booking->cancellation_reason }}</p>
            @endif
        @elseif($status === 'note_sent')
            <p>We shared a new update for your booking <strong>#{{ $booking->id }}</strong>.</p>
            @if(!empty($booking->admin_notes))
                <p><strong>Message:</strong> {{ $booking->admin_notes }}</p>
            @endif
        @elseif($status === 'downpayment_received')
            <p>We have verified your payment reference for booking <strong>#{{ $booking->id }}</strong>. Your downpayment has been received and your booking is confirmed!</p>
            @if($payment)
                <p><strong>Reference:</strong> {{ $payment->reference_number }}</p>
                <p><strong>Amount:</strong> ₱{{ number_format($payment->amount ?? 0, 2) }}</p>
            @endif
        @elseif($status === 'proposal_uploaded')
            <p>A new proposal was uploaded for your booking <strong>#{{ $booking->id }}</strong>. Please log in to review it.</p>
        @else
            <p>Your booking <strong>#{{ $booking->id }}</strong> for <strong>{{ $booking->event_type }}</strong>@if($booking->event_date) on <strong>{{ $booking->event_date->format('F j, Y') }}</strong>@endif is now <strong>{{ (new \App\Models\Booking())->forceFill(['status' => $status])->client_status_label }}</strong>.</p>
            <p>{{ \App\Services\ClientStatusNotifier::content($status)[1] }}</p>
        @endif

        <p><a href="{{ route('client.booking.updates', $booking) }}" style="display:inline-block;background:#2d0b6b;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">View booking updates</a></p>

        <p>If you have questions, reply to this email.</p>
        <p style="color:#888;font-size:12px;margin-top:18px">Raflora Enterprises</p>
    </div>
</body>
</html>
