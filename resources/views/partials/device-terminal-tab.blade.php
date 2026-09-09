{{-- The body of the device Terminal tab.

     Separate from the view that wraps it so the wrapper can put it inside
     core's device chrome when that exists and render it bare when it does not
     -- which is what makes this testable without LibreNMS.

     No margin-top here: <x-device.page> already wraps its slot in
     `.tab-content` with the host's own spacing, and adding a second one gave
     this tab more separation than any core device tab has. --}}
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">
            <i class="fa fa-terminal" aria-hidden="true"></i> {{ __('Terminal') }}
            <span class="pull-right"><small class="text-muted">{{ __('WebTerm records that you opened a terminal here, and when.') }}</small></span>
        </h3>
    </div>
    <div class="panel-body">
        @if (($data['webtermState'] ?? 'denied') !== 'ready')
            @php
                $fix = $data['webtermFix'] ?? null;
                // ReasonCode::remediation() returns a shell command for most
                // refusals and a prose instruction for three of them. Rendering
                // a sentence in a <pre> tells the reader to paste it into a
                // terminal, so the shape decides the treatment.
                $fixIsCommand = is_string($fix) && str_starts_with($fix, './lnms');
            @endphp
            <p class="text-muted">
                <i class="fa fa-lock fa-fw" aria-hidden="true"></i>
                {{ $data['webtermReason'] ?? __('The terminal is unavailable for this device.') }}
            </p>
            @if ($fixIsCommand)
                <p>{{ __('Ask a WebTerm administrator to run this on the LibreNMS server, as the librenms user:') }}</p>
                <pre style="margin-bottom: 0;">{{ $fix }}</pre>
            @elseif (! empty($fix))
                <p style="margin-bottom: 0;">{{ $fix }}</p>
            @endif
        @else
            @include('WebTerm::partials.terminal', [
                'webtermDeviceId' => $data['webtermDeviceId'],
                // Inside the device page the terminal waits to be asked. The
                // tab itself is not consent to open an audited SSH session and
                // spend a concurrency slot -- see the partial's own comment.
                'webtermAutoConnect' => false,
                // The device header and tab bar sit above this, so a 70vh frame
                // would put the last rows of the shell below the fold.
                'webtermFrameHeight' => '60vh',
            ])
        @endif
    </div>
</div>
