    </main><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.app -->
<script src="<?= asset('js/location.js') ?>?v=<?= filemtime(APP_ROOT.'/assets/js/location.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>?v=<?= filemtime(APP_ROOT.'/assets/js/app.js') ?>"></script>
<!-- AUTO QUEUE PROCESSOR: Runs silently when anyone opens HRIS (no cron needed!) -->
<script>
(function(){
  fetch("<?= APP_URL ?>cron/process-queue.php?key=spotcomm-cron-2026&t=" + Date.now())
    .catch(function(){});
})();
</script>
</body>
</html>
