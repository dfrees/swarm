"use strict";(self.webpackChunkswarm=self.webpackChunkswarm||[]).push([[563,486],{90486:function(e,t,n){n.r(t),n.d(t,{default:function(){return f}});var r=n(67294),a=n(45697),l=n.n(a),c=n(26793);function i(e,t){(null==t||t>e.length)&&(t=e.length);for(var n=0,r=new Array(t);n<t;n++)r[n]=e[n];return r}var u=function(e){var t,n,a=(0,r.useRef)(null),l=e.initialTrail,c=e.id,u=(t=(0,r.useState)(l.replace(/\/$/,"").replace(new RegExp("^".concat(c)),"").replace(/.*\/([^/]*)*/,"/$1")),n=2,function(e){if(Array.isArray(e))return e}(t)||function(e,t){var n=null==e?null:"undefined"!=typeof Symbol&&e[Symbol.iterator]||e["@@iterator"];if(null!=n){var r,a,l=[],c=!0,i=!1;try{for(n=n.call(e);!(c=(r=n.next()).done)&&(l.push(r.value),!t||l.length!==t);c=!0);}catch(e){i=!0,a=e}finally{try{c||null==n.return||n.return()}finally{if(i)throw a}}return l}}(t,n)||function(e,t){if(e){if("string"==typeof e)return i(e,t);var n=Object.prototype.toString.call(e).slice(8,-1);return"Object"===n&&e.constructor&&(n=e.constructor.name),"Map"===n||"Set"===n?Array.from(e):"Arguments"===n||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)?i(e,t):void 0}}(t,n)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()),o=u[0],f=u[1];return(0,r.useEffect)((function(){if(null!==o){var e=function(e){f(e.detail)},t=a.current;return t.addEventListener("change-breadcrumb",e),function(){t.removeEventListener("change-breadcrumb",e)}}return""}),[]),null!==o?r.createElement("span",{ref:a,className:"breadcrumb-trail"},o):null},o=function(e){var t=(0,c.$)("menu").t,n=e.title,a=e.id,l=e["*"],i=e.legacyPage;return(0,r.useEffect)((function(){var e;return i?((e=document.createEvent("Event")).initEvent("reloadPhpPage",!0,!1),document.dispatchEvent(e)):function(){var e=document.createEvent("Event");e.initEvent("clearPhpPage",!0,!1),document.dispatchEvent(e)}(),function(){}}),[i]),r.createElement("h1",{className:"page-heading".concat(n?" module-".concat(n):"").concat(i?" legacy-page":"")},n?r.createElement("span",{className:"module-id"},t(n)):"","files"!==n?r.createElement(r.Fragment,null,n&&a?"/":"",r.createElement("span",{className:"page-id"},a),r.createElement(u,{id:(l||"").replace(/^([^/]+)/,"$1"),initialTrail:l||""})):r.createElement(u,{initialTrail:(a?"/".concat(a,"/"):"")+l}))},f=o;o.propTypes={title:l().string,id:l().oneOfType([l().string,l().number]),"*":l().string,legacyPage:l().bool},u.propTypes={initialTrail:l().string,id:l().string},o.defaultProps={id:null,title:null,"*":null,legacyPage:!1},u.defaultProps={id:null,initialTrail:""}},23563:function(e,t,n){n.r(t);var r=n(67294),a=n(45697),l=n.n(a),c=n(31252),i=n(35800),u=n(28889),o=n(28216),f=n(90486),d=n(61384),s=n(86675),p=n(48012),m=(0,r.lazy)((function(){return(0,s.Z)((function(){return Promise.all([n.e(49),n.e(678),n.e(398)]).then(n.bind(n,56398))}))}));function E(){throw new Error}var g=function(e){var t=e.projectId,n=(0,o.I0)();return(0,r.useEffect)((function(){n((0,p.v)(t))}),[t]),r.createElement(i.ErrorBoundary,{FallbackComponent:function(){return r.createElement(u.Z,null,r.createElement(d.Z,{type:"error",text:window.phpError}),r.createElement(c.F0,null,r.createElement(f.default,{legacyPage:!0,default:!0,title:"projects",id:t})))}},window.phpError?r.createElement(E,null):r.createElement(c.F0,null,r.createElement(m,{path:"reviews/*"}),r.createElement(f.default,{legacyPage:!0,default:!0,title:"projects",id:t})))};t.default=function(){return r.createElement(c.F0,null,r.createElement(c.l_,{from:"edit/:projectId",to:"../../:projectId/settings",noThrow:!0}),r.createElement(g,{path:":projectId/*"}),r.createElement(f.default,{legacyPage:!0,default:!0,title:"projects"}))},g.propTypes={projectId:l().string},g.defaultProps={projectId:null}}}]);