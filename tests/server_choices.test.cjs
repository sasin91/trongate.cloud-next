const assert = require('node:assert/strict');
const choices = require('../modules/servers/js/create.js');
const size = (name, available, cost, extra = {}) => ({name, architecture:'arm', disk:40, locations:[{name:'nbg1',available}], prices:[{location:'nbg1',price_monthly:{net:cost}}], ...extra});
const options = {server_types:[size('cax11',true,'4'),size('large',true,'10'),size('cheap',true,'3'),size('sold-out',false,'1'),size('old',true,'2',{deprecated:true})], images:[
    {id:1,architecture:'arm',status:'available',type:'system',disk_size:5},
    {id:2,architecture:'x86',status:'available',type:'system'},
    {id:3,architecture:'arm',status:'available',type:'system',disk_size:80},
    {id:4,architecture:'arm',status:'available',type:'snapshot',labels:{'tc-nixos':'true'}},
    {id:5,architecture:'arm',status:'available',type:'snapshot'},
    {id:6,architecture:'arm',status:'available',type:'system',deprecated:true}
]};
const sizes = choices.sizes(options,'nbg1');
assert.deepEqual(sizes.map(t=>t.name),['cheap','cax11','large']);
assert.equal(choices.preferred(sizes,undefined,'name',t=>t.name==='cax11').name,'cax11');
assert.equal(choices.preferred(sizes,'large','name',t=>t.name==='cax11').name,'large');
assert.equal(choices.preferred(sizes.filter(t=>t.name!=='cax11'),undefined,'name',t=>t.name==='cax11').name,'cheap');
assert.deepEqual(choices.sizes(options,'unavailable'),[]);
assert.deepEqual(choices.images(options,sizes[0]).map(i=>i.id),[4]);
assert.deepEqual(choices.images(options,undefined),[]);
console.log('PASS: availability, defaults, saved choices, price ordering and image compatibility');

assert.equal(choices.suggestedSize([size('small',true,'1',{memory:1}),size('medium',true,'2',{memory:4})]).name,'medium');
assert.equal(choices.suggestedSize([size('small',true,'1',{memory:1}),size('cax11',true,'2',{memory:4})]).name,'cax11');
