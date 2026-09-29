@extends('layouts.app')

@section('title', 'SiteSentinel')

@section('content')
    <div class="flex min-h-screen flex-col items-center justify-center gap-4 px-4">
        <h1 class="text-3xl font-bold tracking-tight">SiteSentinel</h1>
        <p class="max-w-md text-center text-slate-600">
            Website monitoring and security alerts. <a href="{{ route('login') }}" class="underline">Admin sign-in</a>.
        </p>
    </div>
@endsection
