<?php
// Floating page helper: a "?" button on every admin page that opens the
// guide topic covering the page you are on. Hidden on the guide itself.
use App\Support\HelpContent;

$hw_page = isset($page) ? (string)$page : 'home';
if ($hw_page !== 'help'):
    $hw_slug = HelpContent::topicForPage($hw_page);
    $hw_all = HelpContent::visibleTopics($_settings);
    $hw_topic = ($hw_slug !== null && isset($hw_all[$hw_slug])) ? $hw_all[$hw_slug] : null;
?>
<button type="button" id="nv-help-btn" class="btn btn-primary shadow" title="Help for this page"
        data-toggle="modal" data-target="#nv-help-modal" aria-label="Help">
    <i class="fas fa-question"></i>
</button>
<div class="modal fade" id="nv-help-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header py-2">
                <h5 class="modal-title">
                    <i class="fas <?php echo $hw_topic ? $hw_topic['icon'] : 'fa-book-open' ?> mr-2 text-primary"></i>
                    <?php echo $hw_topic ? htmlspecialchars($hw_topic['title']) : 'User Guide' ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <?php if($hw_topic): ?>
                    <p class="text-muted"><?php echo htmlspecialchars($hw_topic['intro']) ?></p>
                    <?php foreach($hw_topic['sections'] as $hw_s): ?>
                        <h6 class="mt-3 mb-2 font-weight-bold"><?php echo htmlspecialchars($hw_s['h']) ?></h6>
                        <ol class="pl-3 mb-2">
                            <?php foreach($hw_s['steps'] as $hw_step): ?><li class="mb-1"><?php echo htmlspecialchars($hw_step) ?></li><?php endforeach; ?>
                        </ol>
                        <?php foreach($hw_s['tips'] as $hw_tip): ?>
                            <p class="mb-1 small"><span class="badge badge-info mr-1">Tip</span><?php echo htmlspecialchars($hw_tip) ?></p>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted mb-0">No page-specific help here yet - the full user guide covers every area of the system.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer py-2">
                <a href="<?php echo base_url ?>admin/?page=help<?php echo $hw_slug ? '#topic-'.$hw_slug : '' ?>" class="btn btn-sm btn-outline-primary rounded-0"><i class="fas fa-book-open mr-1"></i>Open the full user guide</a>
                <button type="button" class="btn btn-sm btn-secondary rounded-0" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<style>
    #nv-help-btn{
        position: fixed; right: 18px; bottom: 18px; z-index: 1035;
        width: 42px; height: 42px; border-radius: 50%; padding: 0;
        display: flex; align-items: center; justify-content: center;
    }
    @media print { #nv-help-btn, #nv-help-modal { display: none !important; } }
</style>
<?php endif; ?>
