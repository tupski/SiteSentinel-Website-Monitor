<h1>[SiteSentinel] {{ $severity }} — {{ $website['name'] ?? 'Website' }}</h1>
<p><strong>Type:</strong> {{ $incident_type ?? 'incident' }} | <strong>Severity:</strong> {{ $severity }} | <strong>Status:</strong> {{ $current_status ?? '' }}</p>
<p><strong>Detected:</strong> {{ $detected_at ?? '' }} (incident #{{ $incident_id }})</p>
<p><strong>Summary:</strong> {{ $summary ?? '' }}</p>
<p><a href="{{ $admin_url }}">Open incident in admin</a></p>
