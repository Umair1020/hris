<?php
/**
 * ============================================================================
 * PERFORMANCE REVIEW — conduct a review
 * Score each KPI; HRIS generates detailed analysis (text + radar chart).
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Performance Review','manager');
$empId=(int)($_GET['emp'] ?? 0);
$emp=fetch_one("SELECT * FROM employees WHERE id=?",[$empId]);
if(!$emp){ set_flash('danger','Employee not found.'); redirect(APP_URL.'modules/performance/index.php'); }
if(!can_access_employee($empId)){ require_login('hr'); }

$kpis=fetch_all("SELECT ek.id, kt.name, kt.description, kt.weight, kt.max_score, ek.period
                 FROM employee_kpis ek JOIN kpi_templates kt ON kt.id=ek.kpi_template_id
                 WHERE ek.employee_id=? ORDER BY kt.weight DESC",[$empId]);

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $period=clean($_POST['period']);
  $scores=[];
  $weighted=0; $totalW=0;
  foreach($kpis as $k){
    $sc=(int)($_POST['kpi_'.$k['id']] ?? 0);
    $scores[]=['kpi'=>$k['name'],'score'=>$sc,'max'=>$k['max_score'],'weight'=>$k['weight']];
    $weighted+=($sc/$k['max_score'])*$k['weight'];
    $totalW+=$k['weight'];
  }
  $overall=$totalW>0?round(($weighted/$totalW)*100):0;
  $rating=$overall>=90?'outstanding':($overall>=80?'exceeds':($overall>=60?'meets':($overall>=40?'needs_improvement':'unsatisfactory')));
  $analysis=generate_analysis($emp,$scores,$overall);

  insert('performance_reviews',[
    'employee_id'=>$empId,'reviewer_id'=>current_employee_id(),'review_period'=>$period,
    'kpi_scores'=>json_encode($scores),'overall_score'=>$overall,'rating'=>$rating,
    'achievements'=>clean($_POST['achievements'] ?? ''),'areas_to_improve'=>clean($_POST['areas'] ?? ''),
    'reviewer_comments'=>$analysis,'status'=>'submitted','review_date'=>today(),
  ]);
  notify($empId,'Performance Review Completed',"Your review for $period is ready. Overall score: $overall/100.",'modules/performance/my.php');
  log_activity('Performance Review',"{$emp['full_name']} → $overall/100 ($rating)");
  set_flash('success','Performance review submitted. Score: '.$overall.'/100.');
  redirect(APP_URL.'modules/performance/review.php?emp='.$empId.'&done=1');
}

$scoresJson=json_encode(array_map(fn($k)=>['label'=>$k['name']], $kpis));
$done=isset($_GET['done']);
?>
<div class="page-head">
  <div><h1>Performance Review</h1><div class="sub"><?= e($emp['full_name']) ?> · <?= e($emp['designation']) ?></div></div>
  <a href="<?= url('modules/performance/index.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<?php if(!$kpis && !$done): ?>
<div class="card card-pad empty"><i class="fa-solid fa-bullseye"></i><p>This employee has no KPIs assigned. Assign KPIs first from the Performance Management page.</p></div>
<?php else: ?>

<div class="grid cols-2">
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px">KPI Scoring</h3>
    <form method="post"><?= csrf_field() ?>
      <div style="margin-bottom:14px"><label>Review Period</label><input type="text" name="period" class="form-control" value="<?= date('Y') ?>-H2" required></div>
      <?php foreach($kpis as $k): ?>
      <div style="padding:12px 0;border-bottom:1px solid var(--border)">
        <div class="flex between center"><div class="bold small"><?= e($k['name']) ?></div><span class="badge badge-purple">Weight <?= $k['weight'] ?></span></div>
        <div class="muted small" style="margin:4px 0 8px"><?= e($k['description']) ?></div>
        <div class="flex center gap" style="gap:8px">
          <input type="range" name="kpi_<?= $k['id'] ?>" min="0" max="<?= $k['max_score'] ?>" value="<?= $k['max_score']*0.8 ?>" class="kpi-range" data-max="<?= $k['max_score'] ?>" style="flex:1" oninput="updateReview()">
          <span class="kpi-val bold" style="width:60px;text-align:right">80</span>
        </div>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:14px"><label>Achievements</label><textarea name="achievements" class="form-control" rows="2"></textarea></div>
      <div style="margin-top:12px"><label>Areas to Improve</label><textarea name="areas" class="form-control" rows="2"></textarea></div>
      <button class="btn btn-primary btn-block" style="margin-top:16px"><i class="fa-solid fa-check"></i> Submit Review &amp; Generate Analysis</button>
    </form>
  </div>

  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px">Live Analysis</h3>
    <canvas id="radar" height="240"></canvas>
    <div class="card card-pad" style="background:#f4f5fb;margin-top:14px">
      <div class="flex between center"><span class="muted small">Projected Overall Score</span><span id="projScore" style="font-family:Oswald;font-size:24px;color:var(--primary)">80</span></div>
      <div id="projRating" style="margin-top:8px"></div>
    </div>
    <div id="projAnalysis" class="small muted" style="margin-top:12px"></div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
var labels=<?= $scoresJson ?>;
var chart=new Chart(document.getElementById('radar'),{type:'radar',
  data:{labels:labels.map(l=>l.label),datasets:[{label:'Score',data:labels.map(()=>80),backgroundColor:'rgba(125,62,242,.2)',borderColor:'#7d3ef2',pointBackgroundColor:'#2e3192'}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{r:{suggestedMin:0,suggestedMax:100}}}
});
function updateReview(){
  var sliders=document.querySelectorAll('.kpi-range');var vals=[];
  var totW=0,weighted=0;
  sliders.forEach(function(s){var v=+s.value,m=+s.dataset.max;
    s.nextElementSibling.textContent=v;vals.push(m>0?Math.round(v/m*100):0);
    // weight unknown here, approximate equal weighting for live preview
  });
  var avg=vals.length?Math.round(vals.reduce((a,b)=>a+b,0)/vals.length):0;
  chart.data.datasets[0].data=vals;chart.update();
  document.getElementById('projScore').textContent=avg;
  var rating=avg>=90?'Outstanding':(avg>=80?'Exceeds Expectations':(avg>=60?'Meets Expectations':(avg>=40?'Needs Improvement':'Unsatisfactory')));
  var col=avg>=80?'green':(avg>=60?'blue':'amber');
  document.getElementById('projRating').innerHTML='<span class="badge badge-'+col+'">'+rating+'</span>';
  document.getElementById('projAnalysis').innerHTML=genText(avg,vals);
}
function genText(avg,vals){
  var t='The employee scored an average of '+avg+'/100 across '+vals.length+' KPIs. ';
  var strong=0,weak=0;vals.forEach(v=>{if(v>=80)strong++;else if(v<60)weak++;});
  if(strong) t+=strong+' KPI(s) show strong performance (≥80). ';
  if(weak) t+=weak+' KPI(s) require attention (<60) and a development plan is recommended. ';
  if(!weak) t+='Performance is consistent across all areas. ';
  return t;
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

<?php
/** Generate a textual performance analysis (words + recommendations). */
function generate_analysis($emp,$scores,$overall){
  $strong=array_filter($scores,fn($s)=>$s['score']/$s['max']>=0.8);
  $weak=array_filter($scores,fn($s)=>$s['score']/$s['max']<0.6);
  $txt = "PERFORMANCE ANALYSIS — ".$emp['full_name']."\n";
  $txt.= "Overall weighted score: ".$overall."/100.\n\n";
  $txt.= "Strengths: ";
  $txt.= $strong ? implode(', ',array_map(fn($s)=>$s['kpi'].' ('.$s['score'].')',$strong)) : 'none below target';
  $txt.= ".\nAreas requiring development: ";
  $txt.= $weak ? implode(', ',array_map(fn($s)=>$s['kpi'].' ('.$s['score'].')',$weak)) : 'none identified';
  $txt .= ".\n\nRecommendation: ";
  if($overall>=80) $txt.="This employee consistently exceeds expectations. Consider recognition, larger responsibilities, and leadership opportunities.";
  elseif($overall>=60) $txt.="Meets expectations. Focus on continuous improvement in the lower-scoring KPIs through targeted training.";
  else $txt.="Below expectations in several areas. A structured Performance Improvement Plan (PIP) with clear milestones and regular check-ins is recommended.";
  return $txt;
}
