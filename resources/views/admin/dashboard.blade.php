<x-admin-layout>
    <x-slot name="title">{{ __('Dashboard') }} — SiteSentinel Admin</x-slot>

    <h1 class="text-2xl font-bold tracking-tight">{{ __('Dashboard') }}</h1>
    <p class="mt-1 text-sm text-slate-600">
        {{ __('Monitoring shell placeholder. Data areas arrive with later phases.') }}
    </p>

    {{-- AC-2-07: availability and security are presented as SEPARATE areas.
         This two-dimensional separation is a structural invariant (AGENTS.md §15.2):
         never merge availability and security into one panel or one status value. --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section aria-labelledby="availability-heading" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 id="availability-heading" class="text-lg font-semibold">{{ __('Availability') }}</h2>
            <p class="mt-2 text-sm text-slate-600">
                {{ __('Reachability, response codes, and response time of monitored websites. UP / DOWN status per website. Delivered by the monitoring engine in a later phase.') }}
            </p>
            <div class="mt-4 rounded border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400">
                {{ __('No availability data yet.') }}
            </div>
        </section>

        <section aria-labelledby="security-heading" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 id="security-heading" class="text-lg font-semibold">{{ __('Security & Content Health') }}</h2>
            <p class="mt-2 text-sm text-slate-600">
                {{ __('Content changes, suspicious keywords, redirects, and SSL findings. OK / INFO / SUSPECT / INCIDENT per website. Delivered by the detection engine in a later phase.') }}
            </p>
            <div class="mt-4 rounded border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400">
                {{ __('No security data yet.') }}
            </div>
        </section>
    </div>
</x-admin-layout>
