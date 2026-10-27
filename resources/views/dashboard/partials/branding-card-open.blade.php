<div class="bg-[#121215] border border-white/[0.07] rounded-2xl p-6 shadow-lg space-y-5">
    <div class="flex items-center justify-between border-b border-white/[0.06] pb-3.5">
        <div class="flex items-center gap-2.5">
            <i data-lucide="globe" class="w-5 h-5 text-emerald-400"></i>
            <h3 class="text-sm font-bold text-white uppercase tracking-wider">Website Settings</h3>
        </div>
        <span class="text-xs font-mono px-2.5 py-1 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">{{ $siteSettings->version }}</span>
    </div>
    <form action="{{ route('dashboard.settings.site.update') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-1.5">
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">Website Title</label>
                <input type="text" name="site_title" value="{{ old('site_title', $siteSettings->site_title) }}" required maxlength="100" placeholder="OmniRoute" class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-600 focus:outline-none focus:border-emerald-500">
            </div>
            <div class="space-y-1.5">
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">Version</label>
                <input type="text" name="version" value="{{ old('version', $siteSettings->version) }}" required maxlength="20" placeholder="v1.0" class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-600 font-mono focus:outline-none focus:border-emerald-500">
            </div>
        </div>
