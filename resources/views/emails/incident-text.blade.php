[SiteSentinel] {{ $severity }} — {{ $website['name'] ?? 'Website' }}: {{ $incident_type ?? 'incident' }} {{ $event_kind ?? '' }}

Website : {{ $website['name'] ?? '' }}
Type    : {{ $incident_type ?? '' }}
Severity: {{ $severity }}
Detected: {{ $detected_at ?? '' }} (incident #{{ $incident_id }})
Status  : {{ $current_status ?? '' }}
Summary : {{ $summary ?? '' }}

Open the incident in the admin area for full detail and evidence:
{{ $admin_url }}
