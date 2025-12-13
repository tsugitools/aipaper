<?php

$adjectives = [
    'Able','Accurate','Alert','Attentive','Balanced','Calm','Careful','Centered','Clear','Collected',
    'Composed','Considerate','Consistent','Contained','Curious','Decisive','Deliberate','Direct','Discrete','Diligent',
    'Earnest','Efficient','Even','Exact','Fair','Firm','Focused','Gentle','Grounded','Humble',
    'Intent','Judicious','Kind','Level','Logical','Measured','Methodical','Mindful','Moderate','Modest',
    'Neutral','Nimble','Observant','Open','Orderly','Patient','Perceptive','Plain','Poised','Practical',
    'Precise','Prepared','Quiet','Rational','Ready','Reflective','Reliable','Reserved','Resolute','Responsive',
    'Sincere','Sound','Stable','Steady','Subtle','Supportive','Systematic','Thoughtful','Thorough','Timely',
    'Tranquil','Trustworthy','Unassuming','Understanding','Upright','Useful','Vigilant','Warm','Wary','Wise',

    'Adaptable','Anchored','Assured','Attuned','Balanced','Careful','Clearheaded','Coherent','Considered','Delicate',
    'Dependable','Detached','Disciplined','Economical','Elastic','Evenhanded','Exacting','Flexible','Frank','Genuine',
    'Harmonious','Honest','Impartial','Intentional','Measured','Natural','Objective','Ordered','Patient','Plainspoken',
    'Prudent','Reasoned','Reflective','Regular','Reserved','Sensible','Serene','Steadfast','Temperate','Unbiased',
    'Watchful','Wellplaced','Welltimed','Whole','Yielding','Settled','Centered','Grounded','Lucid','Balanced'
];

$animals = [
    'Antelope','Badger','Beaver','Bison','Butterfly','Camel','Caribou','Cat','Cheetah','Crane',
    'Deer','Dolphin','Dove','Duck','Eagle','Egret','Elk','Falcon','Finch','Fox',
    'Frog','Gazelle','Goat','Goose','Heron','Horse','Hummingbird','Ibex','Ibis','Jay',
    'Kestrel','Koala','Lark','Lynx','Mallard','Manatee','Marten','Moose','Moth','Newt',
    'Otter','Owl','Panda','Parrot','Pelican','Penguin','Plover','Quail','Rabbit','Raven',
    'Robin','Salamander','Seal','Shearwater','Shrike','Sparrow','Squirrel','Starling','Stork','Swan',
    'Tern','Thrush','Tortoise','Toucan','Trout','Turkey','Turtle','Vole','Wallaby','Weasel',
    'Whale','Wigeon','Wolf','Woodcock','Woodpecker','Wren','Yak','Zebra','Chipmunk','Porpoise',

    'Albatross','Anchovy','Anole','Auk','Barracuda','Bobcat','Bonobo','Bream','Bunting','Capybara',
    'Cormorant','Courser','Cuttlefish','Darter','Dormouse','Eland','Fieldfare','Firefly','Gannet','Grouse',
    'Hare','Harrier','Kingfisher','Lamprey','Leafhopper','Lemming','Loach','Magpie','Mink','Murre',
    'Nuthatch','Oriole','Osprey','Pangolin','Pipit','Puffin','Rail','Redstart','Roedeer','Sandpiper',
    'Skylark','Slowworm','Snipe','Sole','Springbok','Stonechat','Sunbird','Teal','Treefrog','Waxwing',
    'Whimbrel','Whitefish','Wombat','Yellowhammer','Zebu','Zander','Pika','Jerboa','Tamarin','Duiker'
];


if (php_sapi_name() === 'cli') {
    $seed = crc32(time());
    mt_srand($seed);

    $name =
        $adjectives[array_rand($adjectives)] . ' ' .
        $animals[array_rand($animals)];

    echo $name;   // e.g. "Measured Heron"
    echo "\n";
}



