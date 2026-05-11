<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean · Agent command center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-hidden bg-[#040713] font-sans text-[#ecebff] antialiased">
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_16%_8%,rgba(124,58,237,.30),transparent_32%),radial-gradient(circle_at_88%_16%,rgba(45,212,191,.18),transparent_30%),linear-gradient(145deg,#040713_0%,#0b1028_48%,#111833_100%)]"></div>
    <div class="pointer-events-none fixed inset-x-0 top-0 h-32 bg-gradient-to-b from-white/[.05] to-transparent"></div>

    <main class="relative z-10 flex min-h-screen flex-col px-5 py-6 md:px-8">
        <nav class="mx-auto flex w-full max-w-6xl items-center justify-between rounded-full border border-white/10 bg-white/[.04] px-4 py-3 shadow-[0_20px_48px_rgba(2,8,23,.28)] backdrop-blur-md">
            <a href="/" class="flex items-center gap-3 no-underline">
                <span class="grid h-11 w-11 place-items-center rounded-full border border-white/20 bg-white p-1.5 shadow-[0_10px_22px_rgba(0,0,0,.25)]">
                    <img src="{{ asset('images/bean-logo-color.png') }}" alt="HeyBean" class="h-full w-full object-contain">
                </span>
                <span>
                    <span class="block font-sora text-[1rem] font-extrabold tracking-[-.03em] text-white">HeyBean</span>
                    <span class="block text-[.72rem] font-semibold uppercase tracking-[.16em] text-[#b8b4da]">Hermes runtime</span>
                </span>
            </a>
            <div class="hidden items-center gap-2 text-sm text-[#cfdcff] md:flex">
                <span class="rounded-full border border-emerald-300/25 bg-emerald-400/10 px-3 py-1.5 font-bold text-emerald-200">Agent online</span>
                <span class="rounded-full border border-white/10 px-3 py-1.5">Flutter + Laravel</span>
            </div>
        </nav>

        <section class="mx-auto grid w-full max-w-6xl flex-1 items-center gap-8 py-10 lg:grid-cols-[1.02fr_.98fr] lg:py-14">
            <div class="text-center lg:text-left">
                <div class="mx-auto mb-5 inline-flex items-center gap-2 rounded-full border border-[#a78bfa55] bg-[#1a1640]/80 px-3 py-1.5 text-[.78rem] font-bold uppercase tracking-[.14em] text-[#d9d2ff] shadow-[0_14px_34px_rgba(35,18,84,.25)] lg:mx-0">
                    <span class="h-2 w-2 rounded-full bg-emerald-300 shadow-[0_0_16px_rgba(110,231,183,.9)]"></span>
                    Production command center
                </div>
                <h1 class="mx-auto max-w-3xl bg-gradient-to-r from-white via-[#ece3ff] to-[#9cc8ff] bg-clip-text font-sora text-[clamp(3rem,9vw,6.8rem)] font-extrabold leading-[.9] tracking-[-.075em] text-transparent lg:mx-0">
                    Ask Bean to run your day.
                </h1>
                <p class="mx-auto mt-6 max-w-2xl text-[1.05rem] leading-8 text-[#d8d3f5] lg:mx-0">
                    The new HeyBean app pairs the familiar mobile household screens with a real Hermes Agent runtime. Chat creates tasks, reminders, calendar events, activity history, and approval cards that persist through the Laravel API.
                </p>
                <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
                    <a href="#chat-preview" class="rounded-[16px] bg-gradient-to-br from-[#7c3aed] via-[#4f46e5] to-[#2563eb] px-5 py-3 text-center text-sm font-extrabold text-white no-underline shadow-[0_18px_34px_rgba(79,70,229,.36)] transition hover:-translate-y-px">Preview the Laravel screen</a>
                    <a href="/api/health" class="rounded-[16px] border border-white/15 bg-white/[.05] px-5 py-3 text-center text-sm font-extrabold text-[#f3f0ff] no-underline transition hover:border-[#a78bfa66] hover:bg-white/[.08]">API health</a>
                </div>
            </div>

            <section id="chat-preview" class="rounded-[34px] border border-transparent bg-[linear-gradient(180deg,rgba(14,16,37,.96),rgba(10,13,30,.98))_padding-box,linear-gradient(135deg,rgba(129,90,255,.92),rgba(71,121,255,.88)_55%,rgba(45,212,191,.7))_border-box] p-3 shadow-[inset_0_1px_rgba(255,255,255,.08),0_30px_70px_rgba(5,8,24,.44),0_0_0_1px_rgba(167,139,250,.12)] backdrop-blur-sm md:p-4">
                <div class="mb-4 flex flex-wrap justify-center gap-2 px-1">
                    @foreach (['Plan today', 'Add task', 'Set reminder', 'Schedule event'] as $quickAction)
                        <span class="rounded-full border border-[#a78bfa44] bg-[#17133a] px-3 py-1.5 text-[.75rem] font-bold text-[#d9d2ff]">{{ $quickAction }}</span>
                    @endforeach
                </div>

                <div class="grid min-h-[340px] content-end gap-3 rounded-[24px] border border-white/10 bg-[#080d21]/70 p-3 shadow-[inset_0_1px_rgba(255,255,255,.04)]">
                    <article class="mr-auto max-w-[82%] rounded-[20px] rounded-bl-[8px] border border-white/10 bg-[#151a39] px-4 py-3 shadow-[0_12px_26px_rgba(0,0,0,.22)]">
                        <div class="mb-2 flex items-center gap-2 text-[.74rem] font-extrabold uppercase tracking-[.12em] text-emerald-200">
                            <img src="{{ asset('images/bean-logo-color.png') }}" alt="" class="h-6 w-6 rounded-full bg-white p-1">
                            Bean
                        </div>
                        <p class="text-sm leading-6 text-[#f4f0ff]">I can create the task list, schedule the calendar block, and queue risky actions for approval before anything leaves the app.</p>
                    </article>
                    <article class="ml-auto max-w-[82%] rounded-[20px] rounded-br-[8px] bg-gradient-to-br from-[#7c3aed] via-[#4f46e5] to-[#2563eb] px-4 py-3 shadow-[0_14px_28px_rgba(79,70,229,.35)]">
                        <div class="mb-1 text-[.74rem] font-extrabold uppercase tracking-[.12em] text-[#eef2ff]">You</div>
                        <p class="text-sm leading-6 text-white">Plan tomorrow and add a workout at 6pm.</p>
                    </article>
                </div>

                <form class="mt-3 grid items-end gap-2 rounded-[18px] border border-white/12 bg-[linear-gradient(180deg,rgba(28,23,58,.92),rgba(13,18,39,.98))] p-2 shadow-[0_16px_28px_rgba(2,8,22,.34)] md:grid-cols-[minmax(0,1fr)_auto]">
                    <textarea disabled rows="1" class="min-h-[2.85rem] resize-none rounded-[13px] border border-[#a78bfa3d] bg-[#0d1330] px-3.5 py-3 text-[.92rem] leading-6 text-[#f6f2ff] outline-none" placeholder="Ask Bean to create tasks, reminders, or calendar events..."></textarea>
                    <button type="button" disabled class="min-h-[2.85rem] rounded-[14px] bg-gradient-to-br from-[#7c3aed] via-[#4f46e5] to-[#2563eb] px-5 text-[.88rem] font-extrabold text-white shadow-[0_12px_24px_rgba(91,64,182,.34)]">Send</button>
                </form>
            </section>
        </section>

        <section class="mx-auto mb-8 grid w-full max-w-6xl gap-4 md:grid-cols-3">
            @foreach ([['Tasks', 'Low-risk actions can persist immediately.'], ['Approvals', 'Mail, payments, and destructive changes wait for review.'], ['Activity', 'Every structured agent result appears in the event feed.']] as [$title, $copy])
                <article class="rounded-[24px] border border-white/10 bg-white/[.045] p-5 shadow-[0_18px_40px_rgba(3,7,18,.24)] backdrop-blur-sm">
                    <h2 class="font-sora text-lg font-extrabold tracking-[-.03em] text-white">{{ $title }}</h2>
                    <p class="mt-2 text-sm leading-6 text-[#cfc8f7]">{{ $copy }}</p>
                </article>
            @endforeach
        </section>
    </main>
</body>
</html>
