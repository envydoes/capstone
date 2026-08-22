<?php
include "../config/db_connection.php";
session_start();

require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

$logged_in = isset($_SESSION['user_id']);

$role      = $_SESSION['account_role'] ?? '';
if (is_array($role)) {
    $role = implode(', ', $role);
}
$userName  = $_SESSION['user_name']    ?? $_SESSION['user_id'] ?? 'User';
$userEmail = $_SESSION['user_id']      ?? '';
$accId     = $_SESSION['acc_id']       ?? '';
$roleLower = strtolower(trim($role));

// ── Show My Panel only for resident / resident+owner (NOT non-resident) ──
$showMyPanel = $logged_in && (
    $roleLower === 'resident' ||
    $roleLower === 'resident,business/apartment owner'
);

$userName = $userEmail; // fallback to email
$stmt = $conn->prepare('SELECT firstname FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $accId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row && !empty($row['firstname'])) {
        $userName = $row['firstname'];
    }
    $stmt->close();
}

$roleLabel = match($role) {
    'admin'        => 'Admin',
    'resident'     => 'Resident',
    'resident,business/apartment owner'     => 'Resident & Owner',
    'non-resident' => 'Non-Resident',
    'non-resident,business/apartment owner' => 'Non-Resident & Owner',
    'business/apartment owner' => 'Owner',
    'business' => 'Owner',
    default        => 'User',
};

$roleBadgeClass = match($role) {
    'admin'        => 'bg-purple-100 text-purple-700 border border-purple-200',
    'resident'     => 'bg-green-100 text-green-700 border border-green-200',
    'resident,business/apartment owner' => 'bg-green-100 text-green-700 border border-green-200',
    'non-resident' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'non-resident,business/apartment owner' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'business/apartment owner' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'business' => 'bg-blue-100 text-blue-700 border border-blue-200',
    default        => 'bg-gray-100 text-gray-600 border border-gray-200',
};

$initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $userName), 0, 2));
$backHref = $_SERVER['HTTP_REFERER'] ?? '../landing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title><?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); min-height: 100vh; }
    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }
    .hero-banner { background: linear-gradient(135deg, var(--site-primary-darker) 0%, var(--site-primary-dark) 45%, var(--site-primary-dark) 75%, var(--site-primary) 100%); position: relative; overflow: hidden; }
    .hero-banner::before { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse at 30% 60%, rgba(134,239,172,0.08) 0%, transparent 60%); }
    .dot-grid { position: absolute; inset: 0; opacity: 0.06; background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 28px 28px; }
    .circle-deco { position: absolute; border-radius: 50%; border: 48px solid rgba(134,239,172,0.06); }
    .content-card { background: #fff; border: 1px solid color-mix(in srgb, var(--site-primary) 20%, white); border-radius: 22px; box-shadow: 0 8px 40px rgba(var(--site-primary-rgb),0.08); overflow: hidden; }
    .lang-btn { border: 1.5px solid color-mix(in srgb, var(--site-primary-light) 40%, white); color: var(--site-primary-darker); background: var(--site-primary-pale); border-radius: 10px; padding: 8px 16px; font-size: 0.78rem; font-weight: 700; transition: all 0.2s; cursor: pointer; }
    .lang-btn.active { background: #fff; border-color: #fff; color: var(--site-primary-dark); box-shadow: 0 3px 10px rgba(0,0,0,0.12); }
    .lang-btn:not(.active):hover { background: rgba(255,255,255,0.15); color: #fff; border-color: rgba(255,255,255,0.3); }
    .terms-section { border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; margin-bottom: 12px; transition: border-color 0.2s; }
    .terms-section:hover { border-color: var(--site-primary-light); }
    .section-header { display: flex; align-items: center; gap: 14px; padding: 16px 20px; background: #f9fafb; cursor: pointer; transition: background 0.2s; user-select: none; }
    .section-header:hover { background: var(--site-primary-pale); }
    .section-num { width: 30px; height: 30px; border-radius: 8px; background: color-mix(in srgb, var(--site-primary) 20%, white); color: var(--site-primary-dark); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem; flex-shrink: 0; }
    .section-chevron { margin-left: auto; color: #9ca3af; transition: transform 0.25s; flex-shrink: 0; }
    .section-chevron.open { transform: rotate(180deg); }
    .section-body { padding: 0 20px; max-height: 0; overflow: hidden; transition: max-height 0.3s ease, padding 0.3s ease; font-size: 0.9rem; color: #374151; line-height: 1.75; }
    .section-body.open { max-height: 500px; padding: 14px 20px 18px; }
    .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--site-primary-dark); font-weight: 600; font-size: 0.88rem; transition: gap 0.2s, color 0.2s; text-decoration: none; }
    .back-link:hover { color: var(--site-primary-darker); gap: 10px; }
    #readProgress { position: fixed; top: 0; left: 0; height: 3px; background: linear-gradient(90deg, var(--site-primary), var(--site-primary-light)); z-index: 100; width: 0%; transition: width 0.1s linear; }
    #mobile-sidebar { overflow-y: auto; }
    @keyframes fadeUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    .fade-1 { animation: fadeUp 0.5s ease both; }
    .fade-2 { animation: fadeUp 0.5s 0.1s ease both; }
    .fade-3 { animation: fadeUp 0.5s 0.2s ease both; }

    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }

    /* Tailwind-green -> theme color overrides (matches adminLanding.php) */
    .text-green-400 { color: var(--site-primary-light) !important; }
    .bg-green-700 { background-color: var(--site-primary) !important; }
    .hover\:bg-green-800:hover { background-color: var(--site-primary-dark) !important; }
    .text-green-700, .text-green-600, .text-green-500 { color: var(--site-primary) !important; }
    .text-green-900, .text-green-950 { color: var(--site-primary-darker) !important; }
    .bg-green-950 { background-color: var(--site-primary-darker) !important; }
    .border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }
    .bg-green-50 { background-color: var(--site-primary-pale) !important; }
    .text-green-800 { color: var(--site-primary-darker) !important; }
    .from-green-700 { --tw-gradient-from: var(--site-primary-dark) !important; }
    .to-green-600 { --tw-gradient-to: var(--site-primary) !important; }
    .bg-green-800 { background-color: var(--site-primary-dark) !important; }
    .bg-green-100 { background-color: color-mix(in srgb, var(--site-primary) 15%, white) !important; }

    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }
  </style>
</head>
<body class="min-h-screen">

<div id="readProgress"></div>

<!-- ══════════════════ HERO ══════════════════ -->
<div class="hero-banner py-12 sm:py-14 px-4 sm:px-6 fade-1">
  <div class="dot-grid"></div>
  <div class="circle-deco" style="width:320px;height:320px;top:-100px;right:-80px;"></div>
  <div class="circle-deco" style="width:180px;height:180px;bottom:-60px;left:5%;"></div>
  <div class="max-w-4xl mx-auto relative z-10 text-center">
    <div class="inline-flex items-center gap-2 bg-white/10 border border-white/20 px-4 py-1.5 rounded-full text-xs font-semibold uppercase tracking-widest mb-5" style="color:var(--site-primary-pale)">
      <i class="fa-solid fa-file-contract text-xs" style="color:var(--site-primary-pale)"></i> Legal Document
    </div>
    <h1 class="text-white font-bold text-3xl sm:text-4xl mb-3" style="font-family:'Playfair Display',serif;" id="heroTitle">Terms of Service</h1>
    <p class="text-sm" id="heroMeta" style="color:var(--site-primary-pale)">Last Updated: March 2026 &nbsp;·&nbsp; Barangay <?= e($siteSettings['barangay_name']) ?>, <?= e($siteSettings['municipality']) ?></p>
  </div>
</div>

<!-- ══════════════════ MAIN ══════════════════ -->
<main class="max-w-4xl mx-auto px-4 sm:px-6 py-8 sm:py-10">

  <div class="flex items-center justify-between mb-7 fade-2 flex-wrap gap-3">
    <div class="flex gap-2 bg-green-800 p-1 rounded-xl">
      <button id="btnEnglish" type="button" class="lang-btn active"><i class="fa-solid fa-globe mr-1.5"></i>English</button>
      <button id="btnFilipino" type="button" class="lang-btn"><i class="fa-solid fa-flag mr-1.5"></i>Filipino</button>
    </div>
  </div>

  <div class="content-card fade-3">
    <div class="bg-gradient-to-r from-green-700 to-green-600 px-6 sm:px-8 py-5 flex items-center gap-4">
      <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
        <i class="fa-solid fa-scale-balanced text-white text-lg"></i>
      </div>
      <div class="min-w-0">
        <h2 class="text-white font-bold text-lg" id="cardTitle">Terms of Service</h2>
        <p class="text-xs mt-0.5" id="cardMeta" style="color:var(--site-primary-pale)">11 sections · Read time ~3 min</p>
      </div>
      <div class="ml-auto bg-white/15 border border-white/20 rounded-xl px-4 py-2 text-center hidden sm:block flex-shrink-0">
        <p class="text-white font-bold text-lg leading-none">11</p>
        <p class="text-[10px] uppercase tracking-wide mt-0.5" id="sectionLabel" style="color:var(--site-primary-pale)">Sections</p>
      </div>
    </div>
    <div class="px-6 sm:px-8 py-5 bg-green-50 border-b border-green-100 flex items-start gap-3">
      <i class="fa-solid fa-circle-info text-green-500 mt-0.5 flex-shrink-0"></i>
      <p class="text-green-800 text-sm leading-relaxed" id="introNote">
        Please read all terms carefully before using the <?= e($siteSettings['site_title']) ?>. By registering and using this system, you confirm that you have read and understood these terms.
      </p>
    </div>
    <div class="p-4 sm:p-6" id="termsAccordion"></div>
  </div>

  <div class="mt-8 bg-white border border-green-100 rounded-2xl px-5 sm:px-6 py-5 flex items-start gap-4 shadow-sm fade-3">
    <div class="w-10 h-10 rounded-xl bg-green-100 flex items-center justify-center flex-shrink-0">
      <i class="fa-solid fa-handshake text-green-700"></i>
    </div>
    <div>
      <p class="font-bold text-gray-800 text-sm" id="bottomTitle">By using <?= e($siteSettings['site_title']) ?>, you agree to these terms.</p>
      <p class="text-gray-400 text-xs mt-1" id="bottomSub">For questions or concerns, contact us at <a href="mailto:<?= e($siteSettings['email']) ?>" class="text-green-600 hover:underline font-medium"><?= e($siteSettings['email']) ?></a></p>
    </div>
  </div>
</main>

<script>
const content = {
  en: {
    heroTitle:'Terms of Service', heroMeta:'Last Updated: March 2026 · Barangay <?= e($siteSettings['barangay_name']) ?>, <?= e($siteSettings['municipality']) ?>',
    cardTitle:'Terms of Service', cardMeta:'11 sections · Read time ~3 min', sectionLabel:'Sections',
    introNote:'Please read all terms carefully before using the <?= e($siteSettings['site_title']) ?>. By registering and using this system, you confirm that you have read and understood these terms.',
    backLabel:'Back', bottomTitle:'By using <?= e($siteSettings['site_title']) ?>, you agree to these terms.',
    bottomSub:'For questions or concerns, contact us at',
    sections:[
      {title:'Acceptance of Terms',body:'By accessing this platform, you agree to these terms. This system is designed for the residents of Sumacab Este and stakeholders of the local community.'},
      {title:'Purpose of the System',body:'This system facilitates digital barangay services, including resident records, document requests, beneficiary applications, equipment borrowing, business listings, apartment listings, and community announcements.'},
      {title:'User Responsibilities',body:'Users must provide accurate, truthful, and complete information during registration and when submitting forms or requests. False or misleading information may result in account suspension or denial of services.'},
      {title:'Account Security',body:'Users are responsible for maintaining the confidentiality of their login credentials. Activities under the account are considered the responsibility of the account holder.'},
      {title:'Use of Services',body:'The system must be used only for legitimate barangay-related transactions and services. Misuse, including fraudulent requests, spam, or unauthorized access attempts, is strictly prohibited.'},
      {title:'Verification and Approval',body:'Certain services may require review and approval by authorized barangay officials before they are processed.'},
      {title:'Data Collection and Privacy',body:'By registering and using this system, users consent to the collection, storage, and processing of personal information for service delivery and accurate community records.'},
      {title:'Content Responsibility',body:'Business owners and apartment owners submitting listings are responsible for ensuring that all provided information, descriptions, and images are accurate and lawful.'},
      {title:'System Availability',body:'While efforts are made to ensure continuous access, the barangay administration does not guarantee uninterrupted availability due to maintenance, technical issues, or unforeseen events.'},
      {title:'Modification of Terms',body:'The barangay administration reserves the right to update or modify these terms as needed. Users will be notified of significant changes when applicable.'},
      {title:'Acceptance Confirmation',body:'By registering an account and using the system, you acknowledge that you have read, understood, and agreed to these Terms of Service.'},
    ]
  },
  fil: {
    heroTitle:'Mga Tuntunin ng Serbisyo', heroMeta:'Huling na bago: March 2026 · Barangay <?= e($siteSettings['barangay_name']) ?>, <?= e($siteSettings['municipality']) ?>',
    cardTitle:'Mga Tuntunin ng Serbisyo', cardMeta:'11 seksyon · Oras ng pagbabasa ~3 min', sectionLabel:'Mga Seksyon',
    introNote:'Mangyaring basahin nang mabuti ang lahat ng tuntunin bago gamitin ang SumEste Portal. Sa pamamagitan ng pagpaparehistro at paggamit ng sistemang ito, kinukumpirma mo na nabasa at naintindihan mo ang mga tuntuning ito.',
    backLabel:'Bumalik', bottomTitle:'Sa paggamit ng SumEste Portal, sumasang-ayon ka sa mga tuntuning ito.',
    bottomSub:'Para sa mga katanungan o alalahanin, makipag-ugnayan sa amin sa',
    sections:[
      {title:'Pagtanggap sa mga Tuntunin',body:'Sa pag-access sa platform na ito, sumasang-ayon ka sa mga tuntuning ito. Ang sistemang ito ay idinisenyo para sa mga residente ng Sumacab Este at mga stakeholder ng lokal na komunidad.'},
      {title:'Layunin ng Sistema',body:'Ang sistemang ito ay ginawa upang mapadali ang digital na pamamahala ng mga serbisyong barangay, kabilang ang rekord ng residente, kahilingan para sa dokumento, aplikasyon para sa benepisyaryo, paghiram ng kagamitan, listahan ng negosyo at apartment, at mga anunsyo sa komunidad.'},
      {title:'Responsibilidad ng User',body:'Ang mga user ay dapat magbigay ng tama, totoo, at kumpletong impormasyon sa oras ng pagpaparehistro at sa pagsusumite ng anumang form o kahilingan. Ang maling impormasyon ay maaaring magresulta sa suspensyon ng account o pagkakait ng serbisyo.'},
      {title:'Seguridad ng Account',body:'Responsibilidad ng user na panatilihing kumpidensyal ang kanilang login credentials. Anumang aktibidad sa account ay ituturing na pananagutan ng may-ari ng account.'},
      {title:'Paggamit ng mga Serbisyo',body:'Ang sistema ay dapat gamitin lamang para sa lehitimong transaksyon at serbisyo sa barangay. Mahigpit na ipinagbabawal ang maling paggamit tulad ng fraud, spam, at unauthorized access.'},
      {title:'Beripikasyon at Pag-apruba',body:'Ang ilang serbisyo ay maaaring sumailalim sa pagsusuri at pag-apruba ng mga awtorisadong opisyal ng barangay bago ito maproseso.'},
      {title:'Koleksyon ng Datos at Privacy',body:'Sa paggamit ng sistemang ito, sumasang-ayon ang user sa koleksyon, pag-iimbak, at pagproseso ng personal na impormasyon para sa pagbibigay ng serbisyo at tamang rekord ng komunidad.'},
      {title:'Responsibilidad sa Nilalaman',body:'Ang mga may-ari ng negosyo o apartment na nagsusumite ng listing ay may responsibilidad na tiyaking tama, kumpleto, at naaayon sa batas ang impormasyong ibinibigay.'},
      {title:'Availability ng Sistema',body:'Bagama\'t sisikapin ng administrasyon na panatilihing tuloy-tuloy ang serbisyo, hindi nito ginagarantiyahan ang walang patid na availability dahil sa maintenance, teknikal na isyu, o hindi inaasahang pangyayari.'},
      {title:'Pagbabago sa mga Tuntunin',body:'May karapatan ang barangay administration na i-update o baguhin ang mga tuntunin kung kinakailangan. Ipapaalam sa mga user ang mahahalagang pagbabago kapag naaangkop.'},
      {title:'Pagkumpirma sa Kasunduan',body:'Sa pagrehistro at paggamit ng system, kinikilala mo na nabasa, naintindihan, at sinang-ayunan mo ang mga Tuntunin ng Serbisyo na ito.'},
    ]
  }
};

function renderAccordion(lang) {
  const accordion=document.getElementById('termsAccordion'); accordion.innerHTML='';
  content[lang].sections.forEach((sec,i)=>{
    const div=document.createElement('div'); div.className='terms-section';
    div.innerHTML=`<div class="section-header" onclick="toggleSection(${i})"><div class="section-num">${i+1}</div><p class="font-semibold text-gray-800 text-sm">${sec.title}</p><i class="fa-solid fa-chevron-down section-chevron" id="chev${i}"></i></div><div class="section-body" id="body${i}"><p class="text-gray-600">${sec.body}</p></div>`;
    accordion.appendChild(div);
  });
}
function toggleSection(i) {
  const body=document.getElementById('body'+i), chev=document.getElementById('chev'+i), isOpen=body.classList.contains('open');
  document.querySelectorAll('.section-body').forEach(b=>b.classList.remove('open'));
  document.querySelectorAll('.section-chevron').forEach(c=>c.classList.remove('open'));
  if(!isOpen){body.classList.add('open');chev.classList.add('open');}
}
function setLanguage(lang) {
  const d=content[lang];
  ['heroTitle','heroMeta','cardTitle','cardMeta','sectionLabel','introNote','backLabel','bottomTitle'].forEach(k=>{const el=document.getElementById(k);if(el)el.textContent=d[k];});
  document.getElementById('btnEnglish').classList.toggle('active',lang==='en');
  document.getElementById('btnFilipino').classList.toggle('active',lang==='fil');
  renderAccordion(lang);
}
document.getElementById('btnEnglish').addEventListener('click',()=>setLanguage('en'));
document.getElementById('btnFilipino').addEventListener('click',()=>setLanguage('fil'));
window.addEventListener('scroll',()=>{const doc=document.documentElement;document.getElementById('readProgress').style.width=(doc.scrollTop/(doc.scrollHeight-doc.clientHeight)*100)+'%';});
setLanguage('en');

// Profile
function toggleProfileMenu(){
  const d=document.getElementById('profile-dropdown'),c=document.getElementById('profile-chevron');
  const open=!d.classList.contains('hidden'); d.classList.toggle('hidden',open);
  if(c) c.style.transform=open?'':'rotate(180deg)';
}
document.addEventListener('click',function(e){const w=document.getElementById('profile-menu-wrapper');if(w&&!w.contains(e.target)){document.getElementById('profile-dropdown')?.classList.add('hidden');const c=document.getElementById('profile-chevron');if(c)c.style.transform='';}});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.getElementById('profile-dropdown')?.classList.add('hidden');const c=document.getElementById('profile-chevron');if(c)c.style.transform='';}});

// Mobile sidebar
const overlay=document.getElementById('mobile-sidebar-overlay'),sidebar=document.getElementById('mobile-sidebar');
function openSidebar(){overlay.classList.remove('hidden','opacity-0');overlay.classList.add('opacity-80');sidebar.classList.remove('translate-x-full');document.body.style.overflow='hidden';}
function closeSidebar(){overlay.classList.add('opacity-0');sidebar.classList.add('translate-x-full');document.body.style.overflow='';setTimeout(()=>overlay.classList.add('hidden'),250);}
document.getElementById('mobile-menu-btn')?.addEventListener('click',openSidebar);
document.getElementById('mobile-menu-close')?.addEventListener('click',closeSidebar);
overlay?.addEventListener('click',closeSidebar);
</script>
</body>
</html>
