<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean · Real-world AI agent for your day</title>
    <meta name="description" content="HeyBean is a real-world usable AI agent that turns chat into calendar events, reminders, tasks, approvals, and daily follow-through.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{color-scheme:light;--cream:#fff9ee;--paper:#fffef9;--ink:#142018;--muted:#607064;--green:#17b65f;--green-dark:#108144;--sage:#dfeee0;--yellow:#f6d46b;--line:rgba(20,32,24,.12);--shadow:0 30px 80px rgba(29,65,38,.18)}
        *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;min-height:100vh;background:radial-gradient(circle at 12% 4%,rgba(246,212,107,.34),transparent 28rem),radial-gradient(circle at 88% 8%,rgba(23,182,95,.18),transparent 28rem),linear-gradient(135deg,#fffaf0 0%,#edf8ed 52%,#f8fbf2 100%);font-family:'Instrument Sans',ui-sans-serif,system-ui,sans-serif;color:var(--ink)}
        a{color:inherit}.shell{width:min(1120px,calc(100% - 32px));margin:0 auto}.nav{display:flex;align-items:center;justify-content:space-between;padding:22px 0}.brand{display:flex;align-items:center;gap:12px;text-decoration:none;font-weight:900}.brand img{width:46px;height:46px;border-radius:16px;background:white;padding:7px;box-shadow:0 12px 30px rgba(16,129,68,.16)}.brand strong{display:block;font-family:Sora,sans-serif;font-size:1.1rem;letter-spacing:-.04em}.brand span{display:block;color:var(--muted);font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.16em}.nav-links{display:flex;align-items:center;gap:10px}.nav-links a{text-decoration:none;font-weight:800;color:#314437;padding:10px 14px;border-radius:999px}.nav-cta{background:var(--ink)!important;color:white!important;box-shadow:0 12px 28px rgba(20,32,24,.16)}
        .hero{display:grid;grid-template-columns:1.02fr .98fr;gap:44px;align-items:center;padding:54px 0 42px}.eyebrow{display:inline-flex;align-items:center;gap:9px;border:1px solid rgba(23,182,95,.22);background:rgba(255,255,255,.62);backdrop-filter:blur(10px);border-radius:999px;padding:8px 12px;color:var(--green-dark);font-size:.78rem;font-weight:900;text-transform:uppercase;letter-spacing:.14em}.eyebrow:before{content:"";width:9px;height:9px;border-radius:999px;background:var(--green);box-shadow:0 0 0 7px rgba(23,182,95,.12)}h1{font-family:Sora,sans-serif;font-size:clamp(3.15rem,8vw,6.9rem);line-height:.9;letter-spacing:-.085em;margin:18px 0 0;max-width:860px}.highlight{position:relative;display:inline-block}.highlight:after{content:"";position:absolute;left:.04em;right:.02em;bottom:.03em;height:.16em;border-radius:999px;background:var(--yellow);z-index:-1}.lead{max-width:650px;color:#435348;font-size:1.16rem;line-height:1.8;margin:26px 0 0}.hero-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:30px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:52px;border-radius:18px;padding:0 22px;text-decoration:none;font-weight:950;border:1px solid var(--line)}.button.primary{background:linear-gradient(135deg,var(--green),var(--green-dark));color:white;border-color:transparent;box-shadow:0 18px 36px rgba(23,182,95,.28)}.button.secondary{background:rgba(255,255,255,.68);color:var(--ink)}.proof{display:flex;flex-wrap:wrap;gap:14px;margin-top:28px;color:var(--muted);font-size:.94rem;font-weight:700}.proof span{display:inline-flex;align-items:center;gap:8px}.proof span:before{content:"✓";display:grid;place-items:center;width:20px;height:20px;border-radius:999px;background:rgba(23,182,95,.13);color:var(--green-dark);font-weight:900}
        .phone-wrap{position:relative}.phone-wrap:before{content:"";position:absolute;inset:9% -3% -8% 12%;background:linear-gradient(135deg,rgba(23,182,95,.22),rgba(246,212,107,.3));filter:blur(38px);border-radius:44px}.phone{position:relative;border:1px solid rgba(20,32,24,.1);border-radius:38px;background:linear-gradient(180deg,#ffffff,#f7fff7);padding:16px;box-shadow:var(--shadow);overflow:hidden}.phone-top{display:flex;align-items:center;justify-content:space-between;padding:8px 8px 16px}.pill{border-radius:999px;background:#101812;color:white;font-size:.78rem;font-weight:900;padding:8px 12px}.badge{width:40px;height:40px;border-radius:999px;background:var(--green);color:white;display:grid;place-items:center;font-weight:950}.calendar{border:1px solid var(--line);border-radius:28px;overflow:hidden;background:white}.week{display:grid;grid-template-columns:repeat(7,1fr);gap:0;padding:14px 18px 10px;text-align:center;font-weight:950;color:#6a776d}.week b{display:block;color:#17231b;font-size:1.05rem;margin-top:6px}.week .active{background:var(--green);color:white;border-radius:999px;padding:4px}.timeline{display:grid;grid-template-columns:48px 1fr 1fr;min-height:350px;border-top:1px solid var(--line)}.times{color:#718075;font-weight:800;font-size:.82rem}.times div{height:52px;padding-top:10px;text-align:right;padding-right:10px}.day{border-left:1px solid var(--line);position:relative;background:linear-gradient(#fff,#fbfffb)}.day h3{margin:0;padding:14px;text-align:center;border-bottom:1px solid var(--line);font-size:.92rem}.event{position:absolute;left:10px;right:10px;top:104px;border:1px solid rgba(23,182,95,.28);background:rgba(23,182,95,.12);border-radius:12px;padding:9px 10px;color:var(--green-dark);font-weight:950;font-size:.86rem}.event.two{top:184px;background:rgba(246,212,107,.24);border-color:rgba(184,135,8,.2);color:#765a05}.now{position:absolute;left:-1px;right:0;top:248px;border-top:3px solid var(--green)}.now span{position:absolute;left:-36px;top:-13px;border-radius:999px;background:var(--green);color:white;font-size:.75rem;font-weight:950;padding:4px 7px}
        .section{padding:46px 0}.section-title{font-family:Sora,sans-serif;font-size:clamp(2rem,4vw,3.6rem);letter-spacing:-.06em;line-height:1;margin:0 0 12px}.section-copy{color:var(--muted);font-size:1.06rem;line-height:1.75;max-width:720px}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:24px}.card{background:rgba(255,255,255,.72);border:1px solid var(--line);border-radius:26px;padding:24px;box-shadow:0 16px 44px rgba(29,65,38,.08)}.card .icon{width:42px;height:42px;border-radius:15px;display:grid;place-items:center;background:var(--sage);font-weight:950;color:var(--green-dark);margin-bottom:16px}.card h3{font-family:Sora,sans-serif;letter-spacing:-.035em;margin:0 0 10px}.card p{margin:0;color:var(--muted);line-height:1.65}.signup{display:grid;grid-template-columns:.92fr 1.08fr;gap:24px;align-items:stretch;background:linear-gradient(135deg,#142018,#203929);border-radius:34px;padding:26px;color:white;box-shadow:var(--shadow);margin:30px 0 70px}.signup h2{font-family:Sora,sans-serif;font-size:clamp(2rem,4vw,3.7rem);line-height:1;letter-spacing:-.065em;margin:0}.signup p{color:#dcebdd;line-height:1.75}.signup ul{list-style:none;margin:22px 0 0;padding:0;display:grid;gap:12px}.signup li{display:flex;gap:10px;color:#eef8ef;font-weight:750}.signup li:before{content:"✓";color:#8ff0b3}.form{background:#fffef9;color:var(--ink);border-radius:26px;padding:22px}.form label{display:block;font-size:.82rem;font-weight:950;text-transform:uppercase;letter-spacing:.1em;color:#536157;margin:0 0 7px}.field{margin-bottom:14px}.form input,.form textarea{width:100%;border:1px solid var(--line);border-radius:15px;background:#f8fbf4;padding:13px 14px;font:inherit;color:var(--ink);outline:none}.form textarea{min-height:112px;resize:vertical}.form button{width:100%;border:0;border-radius:17px;background:linear-gradient(135deg,var(--green),var(--green-dark));color:white;font-weight:950;font-size:1rem;min-height:52px;cursor:pointer}.status{border-radius:15px;background:rgba(23,182,95,.12);color:var(--green-dark);font-weight:850;padding:12px;margin-bottom:14px}.errors{border-radius:15px;background:rgba(220,38,38,.1);color:#9f1239;font-weight:750;padding:12px;margin-bottom:14px}.footer{padding:24px 0 40px;color:var(--muted);font-weight:750;text-align:center}
        .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.hero-signup{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;max-width:620px;margin-top:30px;padding:8px;background:rgba(255,255,255,.78);border:1px solid rgba(20,32,24,.12);border-radius:22px;box-shadow:0 18px 42px rgba(29,65,38,.12);backdrop-filter:blur(12px)}.hero-signup input{width:100%;min-height:52px;border:0;background:transparent;padding:0 14px;font:inherit;color:var(--ink);outline:none}.hero-signup button{min-height:52px;border:0;border-radius:17px;background:linear-gradient(135deg,var(--green),var(--green-dark));color:white;font-weight:950;font-size:1rem;padding:0 22px;cursor:pointer;box-shadow:0 14px 30px rgba(23,182,95,.25)}.hero-status{max-width:620px;margin-top:12px}.signup-note{background:linear-gradient(135deg,#142018,#203929);border-radius:34px;padding:30px;color:white;box-shadow:var(--shadow);margin:30px 0 70px}.signup-note h2{font-family:Sora,sans-serif;font-size:clamp(2rem,4vw,3.7rem);line-height:1;letter-spacing:-.065em;margin:0}.signup-note p{color:#dcebdd;line-height:1.75;max-width:780px}.signup-note ul{list-style:none;margin:22px 0 0;padding:0;display:grid;gap:12px}.signup-note li{display:flex;gap:10px;color:#eef8ef;font-weight:750}.signup-note li:before{content:"✓";color:#8ff0b3}
        @media(max-width:860px){.nav-links a:not(.nav-cta){display:none}.hero,.signup-note{grid-template-columns:1fr}.hero{padding-top:28px}.hero-signup{grid-template-columns:1fr}.cards{grid-template-columns:1fr}h1{font-size:clamp(3rem,15vw,5rem)}.phone{border-radius:28px}.timeline{grid-template-columns:42px 1fr 1fr}.week{padding-inline:10px}.shell{width:min(100% - 24px,1120px)}}
    </style>
</head>
<body>
    <header class="shell nav">
        <a class="brand" href="/" aria-label="HeyBean home">
            <img src="{{ asset('images/bean-logo-color.png') }}" alt="HeyBean logo">
            <span><strong>HeyBean</strong><span>AI agent for real life</span></span>
        </a>
        <nav class="nav-links" aria-label="Primary navigation">
            <a href="#how-it-works">How it works</a>
            <a href="#early-access" class="nav-cta">Get Early Access</a>
        </nav>
    </header>

    <main class="shell">
        <section class="hero">
            <div>
                <div class="eyebrow">Beta access opening soon</div>
                <h1 aria-label="A real-world usable AI agent for your day">A real-world usable AI agent for your <span class="highlight">day</span>.</h1>
                <p class="lead">Ask Bean to schedule workouts, create reminders, plan your calendar, and keep risky actions waiting for your approval. HeyBean turns plain-language requests into the tasks, calendar events, and follow-through your household actually needs.</p>
                <form class="hero-signup" id="early-access" action="{{ route('early-access.store') }}" method="post">
                    @csrf
                    <label class="sr-only" for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" placeholder="you@example.com">
                    <button type="submit">Get Early Access</button>
                </form>
                @if (session('early_access_status'))
                    <div class="status hero-status" role="status">{{ session('early_access_status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="errors hero-status" role="alert">Please check your email address and try again.</div>
                @endif
                <div class="proof" aria-label="HeyBean trust markers">
                    <span>Tasks, reminders, and calendar</span>
                    <span>Human approval for sensitive actions</span>
                    <span>Built on a real Laravel API</span>
                </div>
            </div>

            <div class="phone-wrap" aria-label="HeyBean calendar preview">
                <div class="phone">
                    <div class="phone-top">
                        <span class="pill">May</span>
                        <span class="badge">1</span>
                    </div>
                    <div class="calendar">
                        <div class="week">
                            <span>M<b>11</b></span><span class="active">T<b>12</b></span><span>W<b>13</b></span><span>T<b>14</b></span><span>F<b>15</b></span><span>S<b>16</b></span><span>S<b>17</b></span>
                        </div>
                        <div class="timeline">
                            <div class="times"><div>9 AM</div><div>10 AM</div><div>11 AM</div><div>Noon</div><div>1 PM</div><div>2 PM</div></div>
                            <div class="day"><h3>Tue — May 12</h3><div class="event">Workout</div><div class="now"><span>5:06</span></div></div>
                            <div class="day"><h3>Wed — May 13</h3><div class="event two">Plan dinner</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section" id="how-it-works">
            <h2 class="section-title">Not another chatbot. A useful operator for everyday life.</h2>
            <p class="section-copy">HeyBean is designed for the requests people actually make: “add workout Monday, Wednesday, Friday,” “remind me before school pickup,” “draft that email but ask me before sending,” and “what needs my attention today?”</p>
            <div class="cards">
                <article class="card"><div class="icon">1</div><h3>Say what you need</h3><p>Chat with Bean in natural language. It understands tasks, reminders, calendar events, schedules, and follow-up questions.</p></article>
                <article class="card"><div class="icon">2</div><h3>Bean updates your day</h3><p>Low-risk actions can be created immediately and show up in the app: timelines, lists, reminders, activity history, and blockers.</p></article>
                <article class="card"><div class="icon">3</div><h3>You stay in control</h3><p>Sensitive work waits for review. Approve emails, destructive changes, payments, or other risky actions before anything happens.</p></article>
            </div>
        </section>

        <section class="signup-note">
            <h2>Get early access to the agent you can actually use.</h2>
            <p>We are inviting early users who want Bean to become a practical daily assistant — not a toy demo. Drop your email above and we will reach out when your invite is ready.</p>
            <ul>
                <li>Private beta invites for the mobile app</li>
                <li>Designed around household planning and personal operations</li>
                <li>Feedback loop with the team building the agent</li>
            </ul>
        </section>
    </main>

    <footer class="shell footer">© {{ date('Y') }} HeyBean. Built for people who want an AI agent that does real work.</footer>
</body>
</html>
