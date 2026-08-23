<?php
namespace Pylon\Core\Modules\Content;
defined('ABSPATH') || exit;
class ContentAnalyzer {
    private array $transition_words = [
        'additionally', 'after', 'afterwards', 'also', 'although', 'another', 'as a result',
        'because', 'before', 'besides', 'but', 'consequently', 'conversely', 'despite',
        'due to', 'equally', 'finally', 'first', 'firstly', 'following', 'for example',
        'for instance', 'further', 'furthermore', 'hence', 'however', 'in addition',
        'in conclusion', 'in contrast', 'in other words', 'in particular', 'in spite of',
        'lastly', 'likewise', 'meanwhile', 'moreover', 'nevertheless', 'next', 'nonetheless',
        'notably', 'on the contrary', 'on the other hand', 'overall', 'plus', 'rather',
        'regardless', 'second', 'secondly', 'similarly', 'since', 'so', 'specifically',
        'still', 'subsequently', 'such as', 'therefore', 'thus', 'ultimately', 'whereas',
        'while', 'yet',
    ];

    private function is_passive(string $sentence): bool {
        return (bool) preg_match('/\b(?:am|is|are|was|were|be|been|being)\s+(\w+ed|written|built|brought|bought|caught|chosen|driven|eaten|given|grown|hidden|kept|known|led|left|lost|made|meant|paid|proven|put|read|said|sold|sent|shown|spoken|struck|taken|taught|thought|told|won|understood|drawn|begun|broken|done|forgotten|found|gone|heard|held|hurt|kept|laid|led|lent|let|lit|overcome|ridden|risen|run|seen|sought|shaken|shut|slept|slid|spent|sung|sunk|swum|torn|thrown|worn|woven|written)\b/i', $sentence);
    }

    private function get_consecutive_starts(array $words): int {
        $count = 0;
        $starts = [];
        foreach ($words as $w) {
            $first = strtolower(substr(trim($w), 0, 1));
            $starts[] = $first;
        }
        $max_consecutive = 1;
        $current = 1;
        for ($i = 1; $i < count($starts); $i++) {
            if ($starts[$i] === $starts[$i - 1]) {
                $current++;
                $max_consecutive = max($max_consecutive, $current);
            } else {
                $current = 1;
            }
        }
        return $max_consecutive;
    }

    public function register(): void {
        add_filter('pylon/metabox/content', [$this, 'render_analysis'], 10, 2);
    }

    public function render_meta_box($post): void {
        $analysis = $this->analyze($post);
        $flesch = $analysis['flesch_score'] ?? 0;
        $flesch_grade = $analysis['flesch_grade'] ?? 0;
        $flesch_label = $flesch >= 60 ? __('Good', 'pylon-seo') : ($flesch >= 30 ? __('Okay', 'pylon-seo') : __('Hard', 'pylon-seo'));
        $flesch_class = $flesch >= 60 ? 'pylon-badge-green' : ($flesch >= 30 ? 'pylon-badge-yellow' : 'pylon-badge-red');
        ?>
        <div class="pylon-analysis">
            <div class="pylon-flex pylon-gap-4 pylon-mb-8 pylon-flex-wrap">
                <?php foreach (['readability' => __('Readability', 'pylon-seo'), 'length' => __('Length', 'pylon-seo'), 'headings' => __('Headings', 'pylon-seo'), 'keywords' => __('Keywords', 'pylon-seo'), 'images' => __('Images', 'pylon-seo'), 'links' => __('Links', 'pylon-seo')] as $key => $label): ?>
                    <span class="pylon-badge <?php echo $analysis[$key]['pass'] ? 'pylon-badge-green' : 'pylon-badge-red'; ?>">
                        <?php echo esc_html($label); ?>: <?php echo $analysis[$key]['pass'] ? '✓' : '✗'; ?>
                    </span>
                <?php endforeach; ?>
                <span class="pylon-badge <?php echo esc_attr($flesch_class); ?>">
                    <?php /* translators: 1: Flesch score, 2: score label, 3: grade level. */ echo esc_html(sprintf(__('Flesch: %1$s (%2$s) — Grade %3$s', 'pylon-seo'), $flesch, $flesch_label, $flesch_grade)); ?>
                </span>
            </div>
            <table class="pylon-table">
                <tbody>
                    <?php foreach ($analysis['checks'] as $check): ?>
                        <tr>
                            <td class="pylon-fw-600 <?php echo $check['pass'] ? 'pylon-color-green' : 'pylon-color-red'; ?>"><?php echo $check['pass'] ? '✓' : '✗'; ?></td>
                            <td><?php echo esc_html($check['label']); ?></td>
                            <td class="pylon-text-right pylon-color-muted"><?php echo esc_html($check['value'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_analysis(int $post_id, array $args = []): void {
        $post = get_post($post_id);
        if (!$post) return;
        $this->render_meta_box($post);
    }

    private function count_syllables(string $word): int {
        $w = strtolower(trim($word));
        if (strlen($w) <= 3) return 1;

        static $ones = ['the','he','she','we','me','be','gone','done','are','were','some','come','have','live','give','whose','these','those','please','cause','cease','chute','crepe','cute','dice','ease','else','eye','fare','fee','fence','fete','five','force','forme','frieze','gage','gate','gauge','geese','glue','gon','grace','graze','gree','grime','guide','guise','gybe','gyve','hare','hate','have','hearse','heart','heath','heave','hence','here','hire','hive','hole','home','hope','huge','hume','ice','ire','jeans','jeers','jive','joie','joule','judge','juice','keen','knee','knife','knock','knoll','know','lace','lade','lake','lance','large','late','laure','lave','lease','leave','ledge','leech','leek','less','liege','lief','life','like','lime','line','live','loaf','loath','lobe','lodge','lofty','loose','lore','love','lunge','lure','lute','lye','make','male','mane','mare','mate','maul','maze','mead','meal','meas','meat','meet','meld','melt','mend','mere','merge','merit','mesh','mess','mew','mews','might','mile','milk','mill','mime','mind','mine','mint','mire','mirth','miss','mist','mite','mock','mode','mold','molt','mood','moon','moor','more','moss','most','moth','move','mown','much','mule','mull','muse','mush','musk','must','mute','mutt','myst','myth','nail','name','nape','nave','near','neat','neck','need','nest','news','next','nice','niche','night','nine','node','noise','none','noon','north','nose','note','noun','nude','null','numb','nun','nurse','nut','oaf','oak','oar','oath','obey','odds','ode','off','officer','often','ogle','oil','oink','old','olive','once','one','onion','only','ooze','open','optic','or','oral','orb','orchid','order','ore','organ','other','ouch','ought','ounce','our','out','oval','oven','over','owl','own','pace','pack','pact','pad','page','paid','pail','pain','pair','pal','pale','pall','palm','pan','pane','pang','pant','pap','paper','pare','park','part','pass','past','paste','pat','patch','path','pause','pave','pawn','pay','peace','peak','peal','pear','pearl','peas','peat','peck','peel','peer','peg','pen','pence','pend','perch','pere','pert','perk','peso','pest','pet','phone','piano','pick','pic','pie','piece','pier','pike','pile','pill','pine','ping','pint','pipe','pit','pitch','pith','pity','place','plain','plan','plane','plant','plate','play','plea','plead','please','pleat','pledge','pluck','plug','plum','plumb','plume','plump','plunge','plu','plus','ply','poach','pocket','poem','poet','point','poise','poke','pole','police','polish','poll','pollen','pond','pool','poor','pop','pope','pore','pork','port','pose','post','pot','potch','pound','pour','pout','pow','power','pram','prank','prate','pray','preach','preen','press','press','prey','price','prick','pride','prime','print','prior','prism','prize','probe','prompt','prone','proof','prop','prose','prow','prude','prune','pub','puff','pull','pulp','pulse','pump','punch','punk','pup','pupil','pure','purge','purr','purse','push','put','putt','quack','quaff','quake','qual','qualm','quart','queen','queer','quell','query','quest','queue','quick','quid','quiet','quill','quilt','quince','quip','quit','quite','quits','quiz','quota','quote','race','rack','raft','rage','raid','rail','rain','raise','rake','ram','ramp','range','rank','rant','rap','rape','rash','rasp','rate','rave','read','real','realm','reap','rear','red','reek','reel','reef','ref','rein','rend','rent','rest','retch','rice','rich','ride','rift','right','rigid','ring','rinse','riot','ripe','rise','risk','road','roam','roar','robe','rock','rode','rod','role','roll','roof','room','root','rope','rose','rosy','rot','rough','round','rouse','route','rove','row','rub','rude','ruff','rug','rule','rump','run','rung','ruse','rush','rust','rut','sack','safe','sage','sail','saint','sake','sale','salt','same','sane','sang','sank','sash','sat','sauce','save','saw','scale','scalp','scan','scar','scare','scene','scent','school','scope','score','scorn','scour','scout','scrap','scream','screen','screw','scrip','scrub','seal','seam','search','seat','sect','seed','seek','seem','seen','self','sell','send','sense','sent','shed','sheen','sheep','sheer','sheet','shelf','shell','shelve','sheer','shift','shine','shirt','shock','shoe','shone','shoot','shore','short','shot','should','shout','shove','show','shred','shrub','shrug','shun','shut','sick','side','sift','sigh','sign','silk','sill','silt','since','sing','sink','sip','sir','site','size','skate','sketch','skill','skim','skin','skip','skit','skull','slab','slack','slag','slain','slake','slap','slate','slave','slay','sled','sleek','sleep','sleet','slew','slid','slight','slim','slime','sling','slip','slit','slope','slosh','slot','slow','slug','slum','slump','slur','slush','smack','small','smart','smash','smear','smell','smile','smoke','smooth','smudge','snack','snag','snap','snare','snarl','sneak','sniff','snore','snort','snow','snub','snuff','soak','soap','soar','sock','sod','soda','soft','soil','sold','sole','solve','some','son','song','soon','soot','sore','sorry','sort','soul','sound','soup','sour','sow','space','spade','spare','spark','sparse','spasm','spat','spate','speak','spear','speck','sped','speed','spell','spend','spent','sperm','spice','spill','spin','spine','spire','spit','spite','splash','split','spoil','spoke','spoon','spore','sport','spot','spout','spray','spree','spur','spy','squad','squat','squid','stab','stack','staff','stage','stain','stair','stake','stale','stall','stamp','stand','stark','start','stash','state','stave','stay','steak','steal','steam','steed','steel','steep','steer','stem','step','stern','stick','stiff','stile','still','sting','stint','stir','stock','stole','stone','stood','stool','stoop','stop','store','stork','storm','story','stout','stove','strap','straw','stray','streak','stream','street','stress','stretch','strike','string','strip','stripe','stroke','strong','struck','strum','strut','stub','stud','study','stuff','stump','stun','stung','stunt','style','sub','such','suck','suds','sue','suede','sugar','suit','suite','sulk','sum','summit','sun','sung','sunk','sure','surf','surge','swan','swap','swarm','sway','swear','sweat','sweep','sweet','swell','swept','swift','swim','swing','swipe','swirl','swish','switch','swore','sworn','swung','sync','tack','tackle','tact','tag','tail','take','tale','talk','tall','tame','tamp','tan','tang','tap','tape','tar','tart','task','taste','taunt','tax','tea','teach','team','tear','tease','tell','ten','tend','tent','term','tern','test','text','than','thank','that','thaw','the','their','them','then','thence','there','these','they','thick','thief','thigh','thin','thing','think','third','thorn','those','thought','thread','three','threw','throb','throne','throng','through','throw','thumb','thus','tick','tide','tie','tier','tight','till','tilt','time','tin','tint','tip','tire','title','toad','toast','today','toe','told','toll','tomb','tome','tone','took','tool','toot','top','tore','torn','toss','touch','tough','tour','tow','town','trace','track','trade','trail','train','trait','trance','trap','trash','tray','tread','treat','tree','trek','trench','trend','trial','tribe','trick','tried','trim','trip','trod','troll','troop','trot','trout','truce','truck','true','trunk','trust','truth','try','tube','tuck','tug','tulip','tumble','tuna','tune','turf','turn','tusk','tutor','twin','twine','twirl','twist','type','ugly','ulcer','uncle','under','unite','up','upon','upper','urge','urn','use','used','usher','usual','utter','vale','valve','vamp','van','vane','vary','vase','vast','vault','veal','veer','veil','vein','vent','verb','verse','very','vest','vet','veto','vice','view','vine','vote','vouch','wade','wage','wail','wait','wake','walk','wall','wand','want','ward','warm','warn','wart','wary','wash','waste','watch','wave','waver','wax','way','weak','wean','wear','weed','week','weep','weigh','weird','welt','wench','west','wet','whale','what','wheat','wheel','when','where','whet','which','while','whim','whine','whip','whirl','whisk','whit','white','who','whole','whom','whom','whoop','whose','wick','wide','wife','wild','will','wilt','wince','winch','wind','wine','wing','wink','wipe','wire','wise','wish','wisp','wit','witch','with','woke','wolf','womb','won','wood','wool','word','wore','work','worm','worn','worse','worst','worth','would','wound','wove','wreck','wrist','write','wrong','wrote','yank','yard','year','yell','yes','yet','yield','yoke','young','your','youth','zeal','zero','zone'];
        if (in_array($w, $ones, true)) return 1;

        $vowel_groups = preg_match_all('/[aeiouy]+/', $w);

        if (preg_match('/[^aeiou]e$/i', $w)) {
            $vowel_groups--;
        }
        if (preg_match('/[^aeiou]le$/i', $w) && $vowel_groups >= 1) {
            $vowel_groups++;
        }
        if (preg_match('/[aeiouy]sm$/i', $w)) {
            $vowel_groups++;
        }
        return max(1, $vowel_groups);
    }

    public function calculate_flesch_score(string $text): array {
        $text = wp_strip_all_tags($text);
        $words = str_word_count($text, true);
        $total_words = count($words);
        if ($total_words === 0) {
            return ['flesch' => 100, 'grade' => 1, 'words' => 0, 'sentences' => 1, 'avg_wps' => 0, 'avg_spw' => 0];
        }

        $sentences = preg_match_all('/[.!?]+/', $text);
        if ($sentences === 0) $sentences = 1;

        $syllables = 0;
        foreach ($words as $w) {
            $syllables += $this->count_syllables($w);
        }

        $avg_wps = $total_words / $sentences;
        $avg_spw = $syllables / $total_words;

        $flesch = 206.835 - 1.015 * $avg_wps - 84.6 * $avg_spw;
        $flesch = max(0, min(100, round($flesch, 1)));

        $grade = 0.39 * $avg_wps + 11.8 * $avg_spw - 15.59;
        $grade = max(1, round($grade, 1));

        return [
            'flesch' => $flesch,
            'grade' => $grade,
            'words' => $total_words,
            'sentences' => $sentences,
            'avg_wps' => round($avg_wps, 1),
            'avg_spw' => round($avg_spw, 2),
        ];
    }

    private function analyze(\WP_Post $post): array {
        $content = $post->post_content;
        $title = $post->post_title;
        $focus_kw = get_post_meta($post->ID, 'pylon_focus_keyword', true);
        $word_count = str_word_count(wp_strip_all_tags($content));
        $sentences = preg_match_all('/[.!?]+/', $content);

        if ($word_count === 0) $word_count = 1;

        $heading_count = preg_match_all('/<h[1-6][^>]*>/i', $content);
        $image_count = preg_match_all('/<img[^>]+>/i', $content);
        preg_match_all('/<img[^>]+>/i', $content, $img_tags);
        $images_no_alt = 0;
        foreach ($img_tags[0] as $img) {
            if (!preg_match('/alt\s*=\s*["\'][^"\'\s]+/i', $img)) {
                $images_no_alt++;
            }
        }
        $internal_links = preg_match_all('/<a[^>]+href=["\']' . preg_quote(home_url(), '/') . '[^"\']*["\']/i', $content);
        $external_links = preg_match_all('/<a[^>]+href=["\']https?:\/\/(?!' . preg_quote(wp_parse_url(home_url(), PHP_URL_HOST), '/') . ')[^"\']*["\']/i', $content);

        $keyword_in_title = !$focus_kw || stripos($title, $focus_kw) !== false;
        $keyword_in_content = !$focus_kw || substr_count(strtolower($content), strtolower($focus_kw)) >= 2;

        // Paragraph analysis
        $paragraphs = explode("\n\n", $content);
        $short_paragraphs = 0;
        $long_paragraphs = 0;
        $paragraph_word_counts = [];
        foreach ($paragraphs as $p) {
            $pw = str_word_count(wp_strip_all_tags($p));
            $paragraph_word_counts[] = $pw;
            if ($pw < 10) $short_paragraphs++;
            if ($pw > 150) $long_paragraphs++;
        }

        // Transition words count
        $content_text = wp_strip_all_tags($content);
        $content_lower = strtolower($content_text);
        $transition_count = 0;
        foreach ($this->transition_words as $tw) {
            $transition_count += preg_match_all('/\b' . preg_quote($tw, '/') . '\b/i', $content_lower);
        }
        $transition_pass = $word_count > 0 && ($transition_count / $word_count * 100) >= 0.5;

        // Passive voice detection (sample first 10 sentences)
        $passive_count = 0;
        $sentences_array = preg_split('/[.!?]+/', $content_text);
        $checked_sentences = min(10, count($sentences_array));
        for ($i = 0; $i < $checked_sentences; $i++) {
            if ($this->is_passive($sentences_array[$i])) {
                $passive_count++;
            }
        }
        $passive_pass = $checked_sentences === 0 || ($passive_count / max($checked_sentences, 1)) <= 0.3;

        // Consecutive sentence starts
        $clean_sentences = array_filter(array_map('trim', $sentences_array));
        $starts = [];
        foreach ($clean_sentences as $s) {
            $words_in_s = str_word_count($s, true);
            if (!empty($words_in_s)) {
                $starts[] = $words_in_s[0];
            }
        }
        $max_consecutive_starts = $this->get_consecutive_starts($starts);
        $consecutive_pass = $max_consecutive_starts <= 3;

        $flesch = $this->calculate_flesch_score($content);
        $flesch_score = $flesch['flesch'];
        $flesch_grade = $flesch['grade'];

        // Cornerstone content check
        $cornerstone_enabled = get_option('pylon_cornerstone_enabled', '1');
        $cornerstone_conflict = false;
        $cornerstone_conflict_posts = [];
        if ($cornerstone_enabled && $focus_kw) {
            $is_cornerstone = get_post_meta($post->ID, 'pylon_cornerstone_content', true) === '1';
            $cornerstone_query = new \WP_Query([
                'post_type' => $post->post_type,
                'post_status' => 'publish',
                'posts_per_page' => 5,
                'post__not_in' => [$post->ID],
                'meta_query' => [
                    ['key' => 'pylon_cornerstone_content', 'value' => '1'],
                ],
                's' => $focus_kw,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);
            if (!empty($cornerstone_query->posts)) {
                $cornerstone_conflict = true;
                foreach ($cornerstone_query->posts as $cp_id) {
                    $cornerstone_conflict_posts[] = $cp_id;
                }
            }
        }

        $checks = [
            ['label' => __('Title length', 'pylon-seo'), 'pass' => strlen($title) >= 10 && strlen($title) <= 70, 'value' => strlen($title) . __(' chars', 'pylon-seo')],
            ['label' => __('Content length', 'pylon-seo'), 'pass' => $word_count >= 300, 'value' => $word_count . __(' words', 'pylon-seo')],
            ['label' => __('Keyword in title', 'pylon-seo'), 'pass' => $keyword_in_title, 'value' => $focus_kw ?: '-'],
            ['label' => __('Keyword in content', 'pylon-seo'), 'pass' => $keyword_in_content, 'value' => __('≥2 mentions', 'pylon-seo')],
            ['label' => __('Heading structure', 'pylon-seo'), 'pass' => $heading_count >= 2, 'value' => $heading_count . __(' headings', 'pylon-seo')],
            ['label' => __('Images', 'pylon-seo'), 'pass' => $image_count >= 1, 'value' => $image_count . __(' images', 'pylon-seo')],
            ['label' => __('Image alt text', 'pylon-seo'), 'pass' => $images_no_alt === 0, 'value' => $images_no_alt . __(' missing alt', 'pylon-seo')],
            ['label' => __('Internal links', 'pylon-seo'), 'pass' => $internal_links >= 1, 'value' => $internal_links . __(' links', 'pylon-seo')],
            ['label' => __('External links', 'pylon-seo'), 'pass' => $external_links >= 1, 'value' => $external_links . __(' links', 'pylon-seo')],
            ['label' => __('Sentence length', 'pylon-seo'), 'pass' => $sentences > 0 && ($word_count / max($sentences, 1)) <= 25, 'value' => round($word_count / max($sentences, 1)) . __(' w/s', 'pylon-seo')],
            ['label' => __('Short paragraphs', 'pylon-seo'), 'pass' => $short_paragraphs <= count($paragraphs) * 0.5, 'value' => $short_paragraphs . __(' short', 'pylon-seo')],
            ['label' => __('Long paragraphs', 'pylon-seo'), 'pass' => $long_paragraphs === 0, 'value' => $long_paragraphs . __(' long', 'pylon-seo') . ' (>150w)'],
            ['label' => __('Transition words', 'pylon-seo'), 'pass' => $transition_pass, 'value' => $transition_count . __(' used', 'pylon-seo')],
            ['label' => __('Passive voice', 'pylon-seo'), 'pass' => $passive_pass, 'value' => $passive_count . '/' . $checked_sentences . __(' sentences', 'pylon-seo')],
            ['label' => __('Consecutive starts', 'pylon-seo'), 'pass' => $consecutive_pass, 'value' => $max_consecutive_starts . 'x'],
            ['label' => __('Flesch Reading Ease', 'pylon-seo'), 'pass' => $flesch_score >= 60, 'value' => $flesch_score . __(' /100', 'pylon-seo') . ' (Grade ' . $flesch_grade . ')'],
        ];

        if ($cornerstone_conflict) {
            $checks[] = [
                'label' => __('Cornerstone conflict', 'pylon-seo'),
                'pass' => false,
                /* translators: %d: number of cornerstone posts using the keyword. */
                'value' => sprintf(__('Keyword in use by %d cornerstone post(s)', 'pylon-seo'), count($cornerstone_conflict_posts)),
            ];
        }

        // AEO / GEO checks — competitors still score classic SEO only.
        $aeo_q = trim((string) get_post_meta($post_id, 'pylon_aeo_question', true));
        $aeo_a = trim((string) get_post_meta($post_id, 'pylon_aeo_answer', true));
        $has_faq_heading = (bool) preg_match('/<h[2-3][^>]*>.*(faq|frequently|questions?)/i', $content);
        $has_direct_answer = strlen(wp_strip_all_tags($aeo_a)) >= 40
            || (bool) preg_match('/<(p|div)[^>]{0,80}>([^<]{40,220})<\/(p|div)>/i', $content);
        $checks[] = [
            'label' => __('AEO question set', 'pylon-seo'),
            'pass' => $aeo_q !== '' || $has_faq_heading,
            'value' => $aeo_q !== '' ? __('Configured', 'pylon-seo') : ($has_faq_heading ? __('FAQ heading found', 'pylon-seo') : __('Missing', 'pylon-seo')),
        ];
        $checks[] = [
            'label' => __('Extractable answer block', 'pylon-seo'),
            'pass' => $has_direct_answer,
            'value' => $has_direct_answer ? __('Likely citeable', 'pylon-seo') : __('Add a short direct answer', 'pylon-seo'),
        ];
        $checks[] = [
            'label' => __('Question-style H2', 'pylon-seo'),
            'pass' => (bool) preg_match('/<h2[^>]*>[^<]*\?/', $content),
            'value' => __('Helps AI Overviews & PAA', 'pylon-seo'),
        ];

        $pass_count = count(array_filter($checks, fn($c) => $c['pass']));
        $total = count($checks);

        return [
            'readability' => ['pass' => $pass_count / $total >= 0.7],
            'length' => ['pass' => $word_count >= 300],
            'headings' => ['pass' => $heading_count >= 2],
            'keywords' => ['pass' => $keyword_in_content],
            'images' => ['pass' => $image_count >= 1 && $images_no_alt === 0],
            'links' => ['pass' => $internal_links >= 1],
            'checks' => $checks,
            'flesch_score' => $flesch_score,
            'flesch_grade' => $flesch_grade,
            'cornerstone_conflict' => $cornerstone_conflict,
            'cornerstone_conflict_posts' => $cornerstone_conflict_posts,
        ];
    }
}
