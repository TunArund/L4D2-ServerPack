maps=(
    "c1m1_hotel"
    "c2m1_highway"
    "c3m1_plankcountry"
    "c4m1_milltown_a"
    "c5m1_waterfront"
    "c6m1_riverbank"
    "c7m1_docks"
    "c8m1_apartment"
    "c9m1_alleys"
    "c10m1_caves"
    "c11m1_greenhouse"
    "c12m1_hilltop"
    "c13m1_alpinecreek"
    "c14m1_junkyard"
)
map_number=${#maps[@]}
random_map=${maps[$RANDOM % $map_number]}
map="+map $random_map"

ip="+ip 0.0.0.0"
cfg="+exec server.cfg"
multi="-fork 2 -netconport 90## -netconpassword woshitunarund"
rmc="+sv_steam_bypass 1 +sv_setmax 32"
heap="-high -heapsize 524288 -num_edicts 4096 -processheap"
tick="-tickrate 100"
difficulty="+z_difficulty normal"
./srcds_run $ip $map $cfg $difficulty -debug $rmc $tick $heap
