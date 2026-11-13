<?php
// Full user guide: every topic relevant to this user and company, with a
// side index, live search and print support. Content: src/Support/HelpContent.php
use App\Support\HelpContent;

$help_topics = HelpContent::visibleTopics($_settings);
?>
<div class="row" id="user-guide">
    <div class="col-md-3 d-print-none">
        <div class="card card-outline rounded-0 shadow-sm position-sticky" style="top:70px;">
            <div class="card-body p-2">
                <input type="text" id="guide-search" class="form-control form-control-sm rounded-0 mb-2" placeholder="Search the guide...">
                <nav class="nav flex-column small" id="guide-index">
                    <?php foreach($help_topics as $slug => $t): ?>
                        <a class="nav-link py-1 px-2" href="#topic-<?php echo $slug ?>"><i class="fas <?php echo $t['icon'] ?> fa-fw mr-1 text-muted"></i><?php echo htmlspecialchars($t['title']) ?></a>
                    <?php endforeach; ?>
                </nav>
                <button class="btn btn-sm btn-outline-secondary btn-block rounded-0 mt-2" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print the guide</button>
            </div>
        </div>
    </div>
    <div class="col-md-9">
        <?php foreach($help_topics as $slug => $t): ?>
        <div class="card card-outline rounded-0 shadow-sm guide-topic" id="topic-<?php echo $slug ?>">
            <div class="card-header">
                <h3 class="card-title font-weight-bold"><i class="fas <?php echo $t['icon'] ?> mr-2 text-primary"></i><?php echo htmlspecialchars($t['title']) ?></h3>
            </div>
            <div class="card-body">
                <p class="text-muted"><?php echo htmlspecialchars($t['intro']) ?></p>
                <?php foreach($t['sections'] as $s): ?>
                    <h5 class="mt-3 mb-2"><?php echo htmlspecialchars($s['h']) ?></h5>
                    <ol class="pl-3 mb-2">
                        <?php foreach($s['steps'] as $step): ?><li class="mb-1"><?php echo htmlspecialchars($step) ?></li><?php endforeach; ?>
                    </ol>
                    <?php foreach($s['tips'] as $tip): ?>
                        <p class="mb-1 small"><span class="badge badge-info mr-1">Tip</span><?php echo htmlspecialchars($tip) ?></p>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div id="guide-no-results" class="alert alert-light border text-center" style="display:none;">Nothing in the guide matches that search.</div>
    </div>
</div>
<script>
    $(function(){
        $('#guide-search').on('input', function(){
            var q = $(this).val().toLowerCase().trim();
            var any = false;
            $('.guide-topic').each(function(){
                var hit = q === '' || $(this).text().toLowerCase().indexOf(q) !== -1;
                $(this).toggle(hit); any = any || hit;
            });
            $('#guide-index a').each(function(){
                $(this).toggle($($(this).attr('href')).is(':visible'));
            });
            $('#guide-no-results').toggle(!any);
        });
        if(location.hash){ var el = $(location.hash); if(el.length){ $('html,body').animate({scrollTop: el.offset().top - 70}, 200); } }
    });
</script>
