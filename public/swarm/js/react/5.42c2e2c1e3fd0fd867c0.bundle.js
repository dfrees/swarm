"use strict";(self.webpackChunkswarm=self.webpackChunkswarm||[]).push([[5],{50998:function(e,t,n){var c=n(87462),r=n(45987),a=n(67294),l=n(86010),o=n(52543),i=n(91742),s=n(83711),d=n(17294),u=n(66987),p=n(73935),f="undefined"==typeof window?a.useEffect:a.useLayoutEffect,m=a.forwardRef((function(e,t){var n=e.alignItems,o=void 0===n?"center":n,m=e.autoFocus,g=void 0!==m&&m,v=e.button,h=void 0!==v&&v,b=e.children,C=e.classes,y=e.className,z=e.component,w=e.ContainerComponent,L=void 0===w?"li":w,x=e.ContainerProps,E=(x=void 0===x?{}:x).className,M=(0,r.Z)(x,["className"]),S=e.dense,Z=void 0!==S&&S,k=e.disabled,N=void 0!==k&&k,H=e.disableGutters,P=void 0!==H&&H,I=e.divider,B=void 0!==I&&I,V=e.focusVisibleClassName,O=e.selected,T=void 0!==O&&O,j=(0,r.Z)(e,["alignItems","autoFocus","button","children","classes","className","component","ContainerComponent","ContainerProps","dense","disabled","disableGutters","divider","focusVisibleClassName","selected"]),R=a.useContext(u.Z),$={dense:Z||R.dense||!1,alignItems:o},A=a.useRef(null);f((function(){g&&A.current&&A.current.focus()}),[g]);var F=a.Children.toArray(b),D=F.length&&(0,s.Z)(F[F.length-1],["ListItemSecondaryAction"]),q=a.useCallback((function(e){A.current=p.findDOMNode(e)}),[]),G=(0,d.Z)(q,t),W=(0,c.Z)({className:(0,l.Z)(C.root,y,$.dense&&C.dense,!P&&C.gutters,B&&C.divider,N&&C.disabled,h&&C.button,"center"!==o&&C.alignItemsFlexStart,D&&C.secondaryAction,T&&C.selected),disabled:N},j),U=z||"li";return h&&(W.component=z||"div",W.focusVisibleClassName=(0,l.Z)(C.focusVisible,V),U=i.Z),D?(U=W.component||z?U:"div","li"===L&&("li"===U?U="div":"li"===W.component&&(W.component="div")),a.createElement(u.Z.Provider,{value:$},a.createElement(L,(0,c.Z)({className:(0,l.Z)(C.container,E),ref:G},M),a.createElement(U,W,F),F.pop()))):a.createElement(u.Z.Provider,{value:$},a.createElement(U,(0,c.Z)({ref:G},W),F))}));t.Z=(0,o.Z)((function(e){return{root:{display:"flex",justifyContent:"flex-start",alignItems:"center",position:"relative",textDecoration:"none",width:"100%",boxSizing:"border-box",textAlign:"left",paddingTop:8,paddingBottom:8,"&$focusVisible":{backgroundColor:e.palette.action.selected},"&$selected, &$selected:hover":{backgroundColor:e.palette.action.selected},"&$disabled":{opacity:.5}},container:{position:"relative"},focusVisible:{},dense:{paddingTop:4,paddingBottom:4},alignItemsFlexStart:{alignItems:"flex-start"},disabled:{},divider:{borderBottom:"1px solid ".concat(e.palette.divider),backgroundClip:"padding-box"},gutters:{paddingLeft:16,paddingRight:16},button:{transition:e.transitions.create("background-color",{duration:e.transitions.duration.shortest}),"&:hover":{textDecoration:"none",backgroundColor:e.palette.action.hover,"@media (hover: none)":{backgroundColor:"transparent"}}},secondaryAction:{paddingRight:48},selected:{}}}),{name:"MuiListItem"})(m)},85639:function(e,t,n){var c=n(45987),r=n(4942),a=n(87462),l=n(67294),o=n(86010),i=n(52543),s=n(50998),d=l.forwardRef((function(e,t){var n,r=e.classes,i=e.className,d=e.component,u=void 0===d?"li":d,p=e.disableGutters,f=void 0!==p&&p,m=e.ListItemClasses,g=e.role,v=void 0===g?"menuitem":g,h=e.selected,b=e.tabIndex,C=(0,c.Z)(e,["classes","className","component","disableGutters","ListItemClasses","role","selected","tabIndex"]);return e.disabled||(n=void 0!==b?b:-1),l.createElement(s.Z,(0,a.Z)({button:!0,role:v,tabIndex:n,component:u,selected:h,disableGutters:f,classes:(0,a.Z)({dense:r.dense},m),className:(0,o.Z)(r.root,i,h&&r.selected,!f&&r.gutters),ref:t},C))}));t.Z=(0,i.Z)((function(e){return{root:(0,a.Z)({},e.typography.body1,(0,r.Z)({minHeight:48,paddingTop:6,paddingBottom:6,boxSizing:"border-box",width:"auto",overflow:"hidden",whiteSpace:"nowrap"},e.breakpoints.up("sm"),{minHeight:"auto"})),gutters:{},selected:{},dense:(0,a.Z)({},e.typography.body2,{minHeight:"auto"})}}),{name:"MuiMenuItem"})(d)},35035:function(e,t,n){n.d(t,{Z:function(){return o}});var c=n(67294),r=n(45697),a=n.n(r),l=n(89420);function o(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon activity",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"activityPath",fill:t,d:"M8.111,13.443C8.602,13.443,9,13.842,9,14.334c0,0.49-0.398,0.889-0.889,0.889H1.889 C1.398,15.223,1,14.824,1,14.334c0-0.492,0.398-0.891,0.889-0.891H8.111z M16.111,8.111C16.602,8.111,17,8.509,17,9 s-0.398,0.889-0.889,0.889H1.889C1.398,9.889,1,9.491,1,9s0.398-0.889,0.889-0.889H16.111z M10.777,2.778 c0.49,0,0.889,0.398,0.889,0.889c0,0.491-0.398,0.889-0.889,0.889H1.889C1.398,4.556,1,4.158,1,3.667 c0-0.491,0.398-0.889,0.889-0.889H10.777z"}))}o.propTypes={fill:a().string,size:a().number},o.defaultProps={fill:l.Z[300],size:16}},31828:function(e,t,n){n.d(t,{Z:function(){return o}});var c=n(67294),r=n(45697),a=n.n(r),l=n(89420);function o(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon cog",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"cogPath",fill:t,d:"M16,7.484c0.24,0.042,0.467,0.159,0.68,0.352S17,8.266,17,8.547v1c0,0.271-0.107,0.49-0.32,0.656S16.24, 10.484,16,10.547l-1.297,0.312c-0.053,0.156-0.107,0.305-0.164,0.445s-0.123,0.279-0.195,0.414l0.688, 1.156c0.135,0.209,0.219,0.447,0.25,0.719s-0.053,0.506-0.25,0.703L14.328,15c-0.197,0.197-0.438, 0.289-0.719,0.273s-0.525-0.092-0.734-0.227l-1.125-0.719c-0.146,0.072-0.291,0.141-0.438,0.203s-0.303, 0.119-0.469,0.172L10.562,16c-0.041,0.24-0.158,0.467-0.352,0.68S9.781,17,9.5,17h-1c-0.271, 0-0.49-0.107-0.656-0.32S7.562,16.24,7.5, 16l-0.312-1.281c-0.167-0.053-0.333-0.111-0.5-0.18s-0.328-0.143-0.484-0.227l-1.156, 0.734c-0.198,0.135-0.44,0.211-0.727,0.227S3.797,15.197,3.609, 15l-0.719-0.703c-0.188-0.197-0.266-0.432-0.234-0.703s0.109-0.51, 0.234-0.719l0.734-1.219c-0.062-0.125-0.123-0.252-0.18-0.383s-0.107-0.268-0.148-0.414L2, 10.547c-0.24-0.062-0.466-0.178-0.68-0.344S1,9.818,1,9.547v-1c0-0.281,0.107-0.518, 0.32-0.711S1.76,7.526,2,7.484l1.281-0.281c0.042-0.146,0.094-0.292,0.156-0.438s0.125-0.287, 0.188-0.422L2.891,5.125C2.766,4.917,2.688,4.677,2.656,4.406s0.047-0.505,0.234-0.703L3.609,3C3.797, 2.802,4.034,2.711,4.32,2.727s0.529,0.091,0.727,0.227l1.156,0.734C6.359,3.604,6.518,3.529,6.68, 3.461s0.326-0.127,0.492-0.18L7.5,2c0.052-0.24,0.162-0.466,0.328-0.68S8.219,1,8.5,1h1c0.281, 0,0.516,0.107,0.703,0.32s0.307,0.435,0.359,0.664l0.281,1.312C11,3.349,11.154,3.406, 11.305,3.469s0.299,0.13,0.445,0.203l1.125-0.719c0.209-0.135,0.453-0.211,0.734-0.227S14.131, 2.802,14.328,3l0.703,0.703c0.197,0.198,0.281,0.432,0.25,0.703s-0.115,0.51-0.25,0.719l-0.688, 1.156c0.072,0.146,0.143,0.294,0.211,0.445s0.123,0.31,0.164,0.477L16,7.484z M16, 9.469l0.016-0.891c-0.01-0.01-0.037-0.029-0.078-0.055s-0.088-0.044-0.141-0.055l-1.859-0.422L13.766, 7.5c-0.031-0.125-0.072-0.25-0.125-0.375s-0.115-0.255-0.188-0.391l-0.25-0.5l0.984-1.641c0.031-0.042, 0.055-0.083,0.07-0.125s0.023-0.073,0.023-0.094l-0.656-0.641c-0.031,0-0.064,0.005-0.102,0.016s-0.07, 0.026-0.102,0.047l-1.609, 1.031l-0.516-0.266c-0.125-0.062-0.25-0.123-0.375-0.18s-0.256-0.102-0.391-0.133L9.984, 4.062L9.578,2.172C9.568,2.13,9.553,2.091,9.531,2.055L9.5,2H8.578C8.557,2.021,8.537,2.052, 8.516,2.094S8.479,2.188,8.469,2.25L8.016,4.062L7.484,4.234C7.349,4.276,7.213,4.326,7.078, 4.383S6.807,4.505,6.672,4.578L6.156,4.844L4.484,3.781L4.406,3.742C4.375,3.727,4.344,3.713, 4.312,3.703L3.656,4.359c0,0.031,0.008,0.068,0.023,0.109s0.039,0.088,0.07,0.141l1.016,1.688l-0.25, 0.484c-0.062,0.135-0.117,0.26-0.164,0.375S4.266,7.385,4.234,7.5L4.062,8.047L2.172,8.469C2.141,8.479, 2.107,8.495,2.07,8.516S2.01,8.547,2,8.547v0.922C2.021,9.49,2.052,9.508,2.094,9.523S2.188,9.553,2.25, 9.562l1.828,0.469l0.172,0.531c0.031,0.104,0.07,0.211,0.117,0.32s0.102,0.221,0.164,0.336l0.234, 0.484L3.75,13.406c-0.031,0.041-0.055,0.084-0.07,0.125s-0.023,0.078-0.023,0.109l0.656,0.641c0.031, 0,0.065-0.008,0.102-0.023s0.07-0.033,0.102-0.055l1.641-1.047l0.516,0.266c0.135,0.072,0.271,0.139, 0.406,0.195s0.271,0.107,0.406,0.148l0.531,0.172l0.469,1.844c0.01,0.053,0.023,0.096,0.039,0.133S8.557, 15.979,8.578,16h0.906c0.01-0.01,0.029-0.037,0.055-0.078s0.045-0.084,0.055-0.125L10, 13.938l0.547-0.188c0.125-0.041,0.25-0.088,0.375-0.141s0.25-0.109,0.375-0.172l0.516-0.266l1.641, 1.047l0.078,0.039c0.031,0.016, 0.062,0.029,0.094, 0.039l0.656-0.656c0-0.031-0.008-0.068-0.023-0.109s-0.039-0.088-0.07-0.141l-0.984-1.625l0.25-0.5c 0.062-0.115,0.117-0.232,0.164-0.352s0.092-0.242, 0.133-0.367l0.172-0.516l1.859-0.469c0.053-0.01,0.096-0.023,0.133-0.039S15.979, 9.49,16,9.469z M9,6c0.834,0,1.545,0.292,2.133,0.875S12.016,8.167,12.016,9c0,0.834-0.295, 1.541-0.883,2.125S9.834,12,9,12c-0.823,0-1.529-0.291-2.117-0.875S6,9.834,6,9c0-0.833, 0.294-1.542,0.883-2.125S8.177,6,9,6z M9,11c0.553,0,1.023-0.195,1.414-0.586S11,9.553, 11,9c0-0.552-0.195-1.023-0.586-1.414S9.553,7,9,7C8.448,7,7.977,7.195,7.586,7.586S7,8.448,7, 9c0,0.553,0.195,1.023,0.586,1.414S8.448,11,9,11z"}))}o.propTypes={fill:a().string,size:a().number},o.defaultProps={fill:l.Z[300],size:16}},88971:function(e,t,n){n.d(t,{Z:function(){return o}});var c=n(67294),r=n(45697),a=n.n(r),l=n(89420);function o(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon speechBubbles",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"speechBubblesPath",fill:t,d:"M1.5,1.488v6.917h3v0.988h-4V0.5h10.99v3.953h-0.996V1.488H1.5z M5.5, 5.441h12v8.893h-2v1.977V17.5l-3.209-3.166H5.5V5.441z M16.5,13.342V6.429h-10v6.913h5.977l2.023, 1.98v-1.98H16.5z"}))}o.propTypes={fill:a().string,size:a().number},o.defaultProps={fill:l.Z[300],size:16}},34906:function(e,t,n){n.d(t,{Z:function(){return o}});var c=n(67294),r=n(45697),a=n.n(r),l=n(89420);function o(e){var t=e.direction,n=e.fill,r=e.size,a="".concat(r,"px"),l="".concat(r,"px"),o="rotate(".concat({up:0,right:90,down:180,left:270}[t],"deg)");return c.createElement("svg",{className:"svgIcon circledTriangle",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:a,transform:o,width:l}},c.createElement("path",{className:"circledTrianglePath",fill:n,d:"M9,1c4.418,0,8,3.582,8,8c0,4.418-3.582,8-8,8c-4.418,0-8-3.582-8-8C1,4.582,4.582,1,9,1z M9,2C5.134, 2,2,5.134,2,9s3.134,7,7,7s7-3.134,7-7S12.866,2,9,2z M9,5l4,6.5H5L9,5z"}))}o.propTypes={direction:a().string,fill:a().string,size:a().number},o.defaultProps={direction:"up",fill:l.Z[300],size:16}},34221:function(e,t,n){n.d(t,{Z:function(){return o}});var c=n(67294),r=n(45697),a=n.n(r),l=n(89420);function o(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon wrench",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"wrenchPath",fill:t,d:"M16.719,4.375c0.084,0.042,0.15,0.102,0.203,0.18S17,4.719,17,4.812c0,0.281-0.006,0.633-0.016, 1.055s-0.053,0.865-0.125,1.328s-0.188,0.912-0.344,1.344s-0.381,0.795-0.672,1.086l-0.203,0.219c-0.375, 0.375-0.797,0.664-1.266,0.867s-0.959,0.305-1.469,0.305c-0.312, 0-0.646-0.047-1-0.141s-0.672-0.213-0.953-0.359l-5.844,5.906c-0.198,0.197-0.419,0.344-0.664,0.438S3.948, 17,3.688,17c-0.25,0-0.498-0.047-0.742-0.141s-0.466-0.24-0.664-0.438l-0.703-0.719C1.193,15.307,1,14.83,1, 14.273s0.193-1.033,0.578-1.43l5.781-5.891c-0.156-0.365-0.258-0.75-0.305-1.156S7.013,4.984,7.07, 4.578s0.172-0.794,0.344-1.164s0.398-0.701,0.68-0.992l0.219-0.219c0.271-0.271,0.612-0.487, 1.023-0.648c0.412-0.162,0.844-0.284,1.297-0.367s0.904-0.135,1.352-0.156S12.834,1,13.188,1h0.125c0.094,0, 0.18,0.023,0.258,0.07s0.139,0.112,0.18,0.195s0.055,0.172,0.039,0.266s-0.055,0.177-0.117, 0.25l-2.25,2.594l2.266,2.344l2.531-2.266c0.072-0.062,0.154-0.102,0.242-0.117S16.635,4.333,16.719, 4.375z M15.156,8.953c0.303-0.312,0.516-0.776,0.641-1.391S16,6.391,16.031,5.891l-2.047,1.844c-0.094, 0.083-0.203,0.123-0.328, 0.117s-0.234-0.055-0.328-0.148l-2.891-2.969c-0.084-0.094-0.127-0.203-0.133-0.328s0.033-0.234, 0.117-0.328l1.828-2.109c-0.469,0.021-1.049,0.094-1.742,0.219S9.307,2.542,8.984,2.875L8.766,3.094C8.391, 3.479,8.156,3.969,8.062,4.562S7.984,5.698,8.109,6.188c0.042,0.177, 0.099,0.346,0.172,0.508s0.162,0.32,0.266,0.477l-6.266,6.391C2.094,13.76,2,13.998,2,14.273s0.094, 0.514,0.281,0.711l0.703,0.719c0.094,0.094,0.203,0.166,0.328,0.219S3.562,16,3.688,16c0.135,0,0.266-0.025, 0.391-0.078s0.234-0.125,0.328-0.219l6.406-6.469c0.125,0.125,0.26,0.234,0.406,0.328s0.297,0.172,0.453, 0.234c0.178,0.072,0.381,0.127,0.609,0.164s0.438,0.055,0.625,0.055c0.385,0,0.756-0.07, 1.109-0.211s0.672-0.352,0.953-0.633L15.156,8.953z"}))}o.propTypes={fill:a().string,size:a().number},o.defaultProps={fill:l.Z[300],size:16}},7005:function(e,t,n){n.r(t),n.d(t,{default:function(){return Y}});var c=n(67294),r=n(28216),a=n(32427),l=n(26793),o=n(282),i=n(22318),s=n(28889),d=n(45697),u=n.n(d),p=n(29829),f=n(85639),m=n(41120),g=n(33901),v=n(45018),h=n(35035),b=n(34906),C=n(89420),y="M14.688,15.5h-4.063c-0.229,0-0.421-0.078-0.577-0.234c-0.158-0.156-0.234-0.349-0.234-0.578v-4.062c0-0.229,0.078-0.422,0.234-0.578s0.348-0.234,0.577-0.234h4.063c0.229,0,0.421,0.078,0.578,0.234c0.156,0.156,0.233,0.35,0.233,0.578v4.062c0,0.229-0.078,0.422-0.233,0.578C15.109,15.422,14.916,15.5,14.688, 15.5zM14.375,10.938h-3.438v3.438h3.438V10.938z M14.688,8.187h-4.063 c-0.229,0-0.421-0.078-0.577-0.235C9.889,7.795,9.812,7.603,9.812,7.374V3.313 c0-0.229,0.078-0.421,0.234-0.578S10.396,2.5,10.625,2.5h4.062c0.229,0,0.422,0.078,0.578,0.235S15.5, 3.084,15.5,3.313v4.063c0,0.229-0.078,0.421-0.234,0.578C15.109,8.109,14.916,8.187,14.688,8.187z M14.375, 3.625h-3.438v3.438h3.438V3.625z M7.375,15.5H3.313c-0.229,0-0.421-0.078-0.578-0.234S2.5, 14.916,2.5,14.688v-4.063c0-0.229,0.078-0.421,0.235-0.577c0.157-0.158,0.349-0.234,0.578-0.234h4.063 c0.229,0,0.421,0.078,0.578,0.234c0.157,0.156,0.235,0.348,0.235,0.577v4.063c0,0.229-0.078,0.421-0.235, 0.578C7.796,15.422,7.604,15.5,7.375,15.5z M7.093,10.907H3.595v3.498h3.499L7.093,10.907L7.093, 10.907z M7.375,8.187H3.313c-0.229,0-0.421-0.078-0.578-0.235S2.5,7.604,2.5,7.375V3.313c0-0.229,0.078-0.421,0.235-0.578S3.084,2.5,3.313,2.5h4.063c0.229,0,0.421,0.078,0.578,0.235c0.157,0.157,0.235,0.349,0.235,0.578v4.063c0,0.229-0.078,0.421-0.235,0.578C7.797,8.111,7.604, 8.187,7.375,8.187z M7.062,3.626H3.626v3.436h3.436L7.062,3.626L7.062,3.626z",z="M30.5,28 C32.4329966,28 34,26.4329966 34,24.5 C34,22.5670034 32.4329966,21 30.5, 21 C28.5670034,21 27,22.5670034 27,24.5 C27,26.4329966 28.5670034,28 30.5,28 Z M33,22 L28,22 L28,27 L33,27 L33,22 Z M33,19 L28,19 C27.7187486,19 27.4817718,18.9036468 27.2890625, 18.7109375 C27.0963532,18.5182282 27,18.2812514 27,18 L27,13 C27,12.7187486 27.0963532, 12.4817718 27.2890625,12.2890625 C27.4817718,12.0963532 27.7187486,12 28,12 L33,12 C33.2812514,12 33.5182282,12.0963532 33.7109375,12.2890625 C33.9036468,12.4817718 34,12.7187486 34,13 L34,18 C34,18.2812514 33.9036468,18.5182282 33.7109375, 18.7109375 C33.5182282,18.9036468 33.2812514,19 33,19 Z M33,13 L28,13 L28,18 L33,18 L33, 13 Z M24,28 L19,28 C18.7187486,28 18.4817718,27.9036468 18.2890625,27.7109375 C18.0963532, 27.5182282 18,27.2812514 18,27 L18,22 C18,21.7187486 18.0963532,21.4817718 18.2890625, 21.2890625 C18.4817718,21.0963532 18.7187486,21 19,21 L24,21 C24.2812514,21 24.5182282, 21.0963532 24.7109375,21.2890625 C24.9036468,21.4817718 25,21.7187486 25,22 L25,27 C25, 27.2812514 24.9036468,27.5182282 24.7109375,27.7109375 C24.5182282,27.9036468 24.2812514,28 24,28 Z M24,22 L19,22 L19,27 L24,27 L24,22 Z M24,19 L19,19 C18.7187486,19 18.4817718, 18.9036468 18.2890625,18.7109375 C18.0963532,18.5182282 18,18.2812514 18,18 L18,13 C18, 12.7187486 18.0963532,12.4817718 18.2890625,12.2890625 C18.4817718,12.0963532 18.7187486, 12 19,12 L24,12 C24.2812514,12 24.5182282,12.0963532 24.7109375,12.2890625 C24.9036468, 12.4817718 25,12.7187486 25,13 L25,18 C25,18.2812514 24.9036468,18.5182282 24.7109375, 18.7109375 C24.5182282,18.9036468 24.2812514,19 24,19 Z M24,13 L19,13 L19,18 L24,18 L24, 13 Z",w=function(e){var t=e.active,n=e.fill,r=e.size,a="".concat(r,"px"),l="".concat(r,"px"),o="rotate(".concat({none:0,bottomRight:0,bottomLeft:90,topLeft:180,topRight:270}[t],"deg)");return c.createElement("svg",{className:"svgIcon grid",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:a,transform:o,width:l}},"none"===t?c.createElement("path",{className:"gridPath",fill:n,d:y}):c.createElement("g",{stroke:"none",strokeWidth:"1",fillRule:"evenodd"},c.createElement("g",{transform:"translate(-18.000000, -60.000000)",fillRule:"nonzero"},c.createElement("g",{transform:"translate(0.000000, 48.000000)"},c.createElement("path",{className:"gridPath ".concat(t),fill:n,d:z})))))},L=w;function x(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon folder",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"folderPath",fill:t,d:"M15.465,4.65c0.404,0,0.762,0.149,1.07,0.447C16.846,5.395,17,5.738,17,6.128v7.394c0,0.391-0.154, 0.734-0.465,1.031C16.227,14.852,15.869,15,15.465,15H2.536c-0.405,0-0.762-0.148-1.071-0.447C1.155, 14.256,1,13.912,1,13.521V4.478c0-0.39,0.155-0.733,0.464-1.031S2.131,3,2.536,3h4.929L9, 4.65H15.465z M15.857,13.521V6.128c0-0.252-0.131-0.378-0.393-0.378H2.143v7.771c0,0.252,0.131,0.379, 0.393,0.379h12.929C15.727,13.9,15.857,13.773,15.857,13.521z"}))}function E(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon people",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"peoplePath",fill:t,d:"M12.5,4c-0.404,0-0.756,0.147-1.054,0.443C11.148,4.738,11,5.084,11,5.482s0.148,0.75,0.446, 1.057S12.096,7,12.5,7s0.756-0.15,1.054-0.452S14,5.895,14,5.491c0-0.404-0.152-0.753-0.455-1.048C13.241, 4.147,12.893,4,12.5,4z M12.509,8c-0.455,0-0.874-0.115-1.255-0.344s-0.686-0.535-0.912-0.919C10.113, 6.354,10,5.933,10,5.474c0-0.458,0.113-0.876,0.342-1.254c0.227-0.378,0.531-0.676,0.912-0.893C11.635, 3.109,12.054,3,12.509,3c0.454,0,0.873,0.112,1.254,0.335c0.382,0.224,0.683,0.524,0.904,0.902C14.889, 4.615,15,5.027,15,5.474s-0.113,0.865-0.342,1.254c-0.227,0.39-0.531,0.699-0.912,0.928S12.952,8,12.509, 8z M5.5,4C5.095,4,4.744,4.147,4.446,4.443C4.149,4.738,4,5.084,4,5.482s0.149,0.75,0.446,1.057C4.744, 6.846,5.095,7,5.5,7s0.756-0.15,1.054-0.452C6.851,6.247,7,5.895,7, 5.491c0-0.404-0.152-0.753-0.456-1.048C6.241,4.147,5.893,4,5.5,4z M5.509,8C5.054,8,4.635,7.885,4.254, 7.656C3.873,7.427,3.569,7.121,3.341,6.737S3,5.933,3,5.474C3,5.016,3.114,4.598,3.341,4.22c0.228-0.378, 0.532-0.676,0.913-0.893C4.635,3.109,5.054,3,5.509,3c0.455,0,0.873,0.112,1.254,0.335c0.381,0.224,0.683, 0.524,0.904,0.902C7.889,4.615,8,5.027,8,5.474S7.886,6.339,7.659,6.729S7.127, 7.427,6.746,7.656C6.365,7.885,5.952,8,5.509,8z M16, 14v-1.8c0-0.128-0.191-0.622-0.574-0.813c-0.384-0.191-0.852-0.357-1.402-0.496 c-0.622-0.149-1.215-0.224-1.777-0.224c-0.611,0-1.795-0.056-2.292,0.437c0.132,0.139,0.273,0.553, 0.333,0.681c0.061,0.128,0.09,0.267,0.09,0.416V14H16z M9,14v-1.98c0-0.128-1.486-1.149-2-1.288 c-0.581-0.149-0.975-0.364-1.5-0.364s-1.235,0.215-1.816,0.364C3.17,10.87,2,11.892,2,12.02V14H9z M12.277, 9c0.568,0,1.208,0.106,1.916,0.32c0.791,0.227,1.436,0.526,1.936,0.9C16.709,10.674,17,11.18, 17,11.74V15H1v-3.26c0-0.561,0.296-1.066,0.889-1.52c0.523-0.266, 1.092-0.479,1.708-0.639C11.301,9.073,11.824,9,12.277,9z"}))}w.propTypes={active:u().oneOf(["none","bottomRight","bottomLeft","topLeft","topRight"]),fill:u().string,size:u().number},w.defaultProps={active:"none",fill:C.Z[300],size:16},x.propTypes={fill:u().string,size:u().number},x.defaultProps={fill:C.Z[300],size:16},E.propTypes={fill:u().string,size:u().number},E.defaultProps={fill:C.Z[300],size:16};var M=n(34221);function S(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon tickList",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"tickListPath",fill:t,d:"M15.18,2.5C15.418,2.5,16,3.262,16,3.505l-0.001, 3.994h-0.998l0.001-3.724c0-0.183-0.09-0.275-0.27-0.275H2.289C2.109,3.5,2.02,3.592,2.02,3.775V14.19c0, 0.069,0.034,0.138,0.101,0.206C2.188,14.466,2.255,14.5,2.322,14.5l5.688-0.001V15.5H1.913c-0.238, 0-0.446-0.092-0.624-0.273s-0.268-0.395-0.268-0.639c-0.028-7.149-0.028-10.899,0-11.25C1.063, 2.813,1.651,2.5,1.913,2.5H15.18z M15.967,9.5L17,10.562L12.15,15.5L9,12.332l1.033-1.045l2.117, 2.105L15.967,9.5z M7,11.5v1H4v-1H7z M10,8.5v1H4v-1H10z M12,5.5v1H4v-1H12z"}))}S.propTypes={fill:u().string,size:u().number},S.defaultProps={fill:C.Z[300],size:16};var Z=n(88971),k=n(31828);function N(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon flow",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"flowPath",fill:t,d:"M13.5,0.5C14.881,0.5,16,1.619,16,3c0,1.209-0.859,2.219-2,2.45V7.5l-4.497,4H9.5v1.051c1.141,0.23, 2,1.24,2,2.449c0,1.381-1.119,2.5-2.5,2.5S6.5,16.381,6.5,15c0-1.209,0.859-2.219,2-2.45l0-1.05H8.497L4, 7.5l0-2.05C2.859,5.218,2,4.209,2,3c0-1.381,1.119-2.5,2.5-2.5S7,1.619,7,3c0,1.209-0.859,2.219-2, 2.45l0,1.727L8.989,10.5h0.02L13,7.177V5.45c-1.141-0.231-2-1.24-2-2.45C11,1.619,12.119,0.5,13.5,0.5z M9, 13.5c-0.829,0-1.5,0.672-1.5,1.5s0.671,1.5,1.5,1.5c0.828,0,1.5-0.672,1.5-1.5S9.828,13.5,9,13.5z M4.5, 1.5C3.671,1.5,3,2.171,3,3s0.671,1.5,1.5,1.5S6,3.829,6,3S5.329,1.5,4.5,1.5zM13.5,1.5C12.672, 1.5,12,2.171,12,3s0.672,1.5,1.5,1.5S15,3.829,15,3S14.328,1.5,13.5,1.5z"}))}function H(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon linedClipboard",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"linedClipboardPath",fill:t,d:"M12,2.5H6v2h6V2.5z M13,8.5v1H5v-1H13z M10,11.5v1H5v-1H10z M4.7, 4.5H3v11h12v-11h-1.7v1.1H4.7V4.5z M12.9,1.5 V3h2.3C15.7,3,16,3.3,16,3.7v12c0,0.4-0.3, 0.7-0.8,0.7H2.8c-0.4,0-0.8-0.3-0.8-0.7v-12C2,3.3,2.3,3,2.8,3h2.3V1.5H12.9z"}))}N.propTypes={fill:u().string,size:u().number},N.defaultProps={fill:C.Z[300],size:16},H.propTypes={fill:u().string,size:u().number},H.defaultProps={fill:C.Z[300],size:16};var P=function(e){var t=e.fill,n=e.size,r="".concat(n,"px"),a="".concat(n,"px");return c.createElement("svg",{className:"svgIcon menu",viewBox:"0 0 18 18",enableBackground:"new 0 0 18 18",style:{height:r,width:a}},c.createElement("path",{className:"menuPath",fill:t,d:"M2,4.545h14v1.273H2V4.545z M2,9.636V8.364h14v1.271L2,9.636L2,9.636z M2,13.455v-1.273h14 v1.273H2z"}))},I=P;P.propTypes={fill:u().string,size:u().number},P.defaultProps={fill:C.Z[300],size:16};var B=function(e){var t=e.variant;switch(t){case"activity":return c.createElement(h.Z,{fill:"parent",size:14});case"changes":return c.createElement(b.Z,{fill:"parent",size:14});case"dashboard":return c.createElement(L,{fill:"parent",size:14,active:"bottomRight"});case"files":return c.createElement(x,{fill:"parent",size:14});case"groups":return c.createElement(E,{fill:"parent",size:14});case"jobs":return c.createElement(M.Z,{fill:"parent",size:14});case"overview":return c.createElement(L,{fill:"parent",size:14});case"projects":return c.createElement(S,{fill:"parent",size:14});case"reviews":return c.createElement(Z.Z,{fill:"parent",size:14});case"settings":return c.createElement(k.Z,{fill:"parent",size:14});case"workflows":return c.createElement(N,{fill:"parent",size:14});case"tests":return c.createElement(H,{fill:"parent",size:14});case"toggle":return c.createElement(I,{fill:"parent",size:14});default:return c.createElement("svg",{viewBox:"0 0 18 18",className:"svgIcon ".concat(t,"MenuIcon"),style:{height:14,width:14}},c.createElement("path",{className:"defaultPath ".concat(t,"MenuPath"),fill:"parent",d:"M9.001,7.114c0.509,0,0.949,0.188,1.324,0.562C10.699,8.049,10.886,8.492,10.886,9c0,0.508-0.187,0.952-0.561,1.325c-0.375,0.374-0.815,0.561-1.324,0.561c-0.509,0-0.953-0.187-1.326-0.561C7.301,9.952,7.114,9.509,7.114,9c0-0.509,0.188-0.951,0.562-1.325S8.492,7.114,9.001,7.114z M14.614,7.114c0.509,0,0.949,0.188,1.323,0.562C16.313,8.05,16.5,8.492,16.5,9c0,0.508-0.187,0.952-0.562,1.325c-0.374,0.374-0.814,0.561-1.323,0.561s-0.952-0.187-1.325-0.561C12.915,9.952,12.729,9.509,12.729,9c0-0.509,0.186-0.951,0.561-1.325C13.662,7.301,14.105,7.114,14.614,7.114z M3.387,7.114c0.509,0,0.95,0.188,1.325,0.562C5.085,8.05,5.272,8.492,5.272,9c0,0.508-0.188,0.952-0.562,1.325c-0.375,0.374-0.815,0.561-1.325,0.561c-0.509,0-0.952-0.187-1.326-0.561C1.688,9.952,1.5,9.509,1.5,9c0-0.509,0.188-0.951,0.562-1.325S2.878,7.114,3.387,7.114z"}))}};B.propTypes={variant:u().string.isRequired};var V=B,O=n(44091),T=(0,a.Z)((function(e){return{root:{"&:not(.clickable)":{cursor:"unset"},backgroundColor:e.palette.colors.black[100],textTransform:"capitalize",height:e.spacing(5),padding:0,"&:hover":{backgroundColor:e.palette.colors.black[100],"& .swarmLink":{color:e.palette.colors.white[0]},"& .svgIcon":{fill:e.palette.colors.white[0]}},"&.Mui-selected":{borderLeftStyle:"solid",borderLeftWidth:e.spacing(.5),color:e.palette.colors.white[0],borderLeftColor:e.palette.colors.green[400],backgroundColor:e.palette.colors.black[200],borderRightColor:e.palette.colors.black[200],"&:hover":{backgroundColor:e.palette.colors.black[200]},"& .menuItemPopover":{backgroundColor:e.palette.colors.black[200]},"& .swarmLink":{color:e.palette.colors.white[0]},"& .svgIcon":{marginLeft:e.spacing(-.5),fill:e.palette.colors.white[0]}},"& .swarmLink":{color:e.palette.colors.grey[250],textOverflow:"ellipsis",overflow:"hidden","&:visited":{color:e.palette.colors.grey[250]},textDecoration:"none",width:"100%",padding:e.spacing(1,1,1,0)},"& .MuiTouchRipple-root":{visibility:"hidden"},"& .svgIcon":{paddingLeft:e.spacing(2.5),paddingRight:e.spacing(1.25),fill:e.palette.colors.grey[300]}}}})),j=function(e){var t=e.id,n=e.cssClass,a=e.target,o=e.title,s=e.index,d=e.onComponentClick,u=T(),p=(0,l.$)("menu").t,m=function(e,t,n,c){if(0===t.indexOf(e+n.replace(/^\/|\/*$/g,""))&&(n.length>1||t.replace(e,"/")===n))return!0;if(t.replace(e,"").replace(/([^/]+)\/.*/,"$1")===n.replace(/.*\/([^/]+)\/*/,"$1"))return!0;if(0===c){var r=n.replace(/(.*)\/[^/]+\/*$/,"$1");if(r.length>"/".length)return t.replace(/\/*$/,"").endsWith(r)}return!1}((0,r.v9)(O.BR),window.location.pathname,a,s);return c.createElement(f.Z,{key:t,selected:m,onClick:d,className:"".concat(u.root," menuItem menuItem-").concat(t," ").concat(n)},c.createElement(v.Z,{react:-1!==n.indexOf("component"),target:a},c.createElement(V,{variant:t}),c.createElement(i.Z,{variant:"body2",className:"smaller innerContent"},p(o))))};j.propTypes={id:u().string,cssClass:u().string,target:u().string,title:u().string,index:u().number,onComponentClick:u().func},j.defaultProps={id:"",cssClass:"",target:"",title:"",index:1,onComponentClick:function(){}};var R=j,$=n(71744),A=n(71476);function F(e,t){var n=Object.keys(e);if(Object.getOwnPropertySymbols){var c=Object.getOwnPropertySymbols(e);t&&(c=c.filter((function(t){return Object.getOwnPropertyDescriptor(e,t).enumerable}))),n.push.apply(n,c)}return n}function D(e,t,n){return t in e?Object.defineProperty(e,t,{value:n,enumerable:!0,configurable:!0,writable:!0}):e[t]=n,e}var q=function(e){var t=e.project,n=(0,c.useMemo)((function(){return function(e){var t=e.project,n=function(e){for(var t=1;t<arguments.length;t++){var n=null!=arguments[t]?arguments[t]:{};t%2?F(Object(n),!0).forEach((function(t){D(e,t,n[t])})):Object.getOwnPropertyDescriptors?Object.defineProperties(e,Object.getOwnPropertyDescriptors(n)):F(Object(n)).forEach((function(t){Object.defineProperty(e,t,Object.getOwnPropertyDescriptor(n,t))}))}return e}({},t?{project:t}:[]);return{target:"menus",queryParams:n}}({project:t})}),[t]),r=n.target,a=n.queryParams;return(0,A.ib)(r,{queryParams:a})},G=(0,m.Z)((function(e){return{root:{paddingTop:0,paddingBottom:0,"& .contextHeading":{height:e.spacing(5),cursor:"text",backgroundColor:e.palette.colors.black[100],"& .MuiAvatar-root":{height:e.spacing(2.5),width:e.spacing(2.5),backgroundColor:e.palette.colors.grey[250],color:e.palette.colors.black[100]},"& .contextFirstLetter":{marginRight:e.spacing(.5)}},"& .contextName":{display:"inline-block",color:e.palette.colors.white[0],overflow:"hidden",textOverflow:"ellipsis",whiteSpace:"nowrap",marginLeft:e.spacing(.5),"& span":{lineHeight:"".concat(e.spacing(2.5)).concat("px")}},"& li":{backgroundColor:e.palette.colors.black[100],"&:hover":{backgroundColor:e.palette.colors.black[100]},"& .suspense":{color:e.palette.colors.grey[300],padding:e.spacing(.25,.5)}}}}})),W=function(e){var t=e.id,n=e.context,r=e.onComponentSelect,a=e.fetchFailed,l=q({project:n}),o=l.data,d=l.messages,u=G().root;return(0,c.useEffect)((function(){d&&d.length>0&&a()}),[d]),c.createElement(p.Z,{className:"swarmMenu ".concat(u,"  ").concat(t)},o&&o.contextMetadata.contextName?c.createElement(f.Z,{className:"".concat(t,"Heading"),disableRipple:!0},c.createElement(s.Z,{className:"".concat(t,"FirstLetter")},c.createElement(g.Z,null,c.createElement(i.Z,{className:"smaller"},o.contextMetadata.contextName.substring(0,1)))),c.createElement(s.Z,{className:"".concat(t,"Name")},c.createElement(i.Z,{variant:"body2",className:"smaller"},o.contextMetadata.contextName))):null,o?o.menu.map((function(e,a){return c.createElement(R,{key:"".concat(t,"-menu-").concat(e.id),index:a,id:e.id,target:e.target,title:e.title,cssClass:e.cssClass,onComponentClick:function(){!n&&r&&r(n)}})})):d?"":c.createElement(f.Z,null,c.createElement($.Z,null)))},U=W;W.propTypes={id:u().oneOfType([u().string,u().number]).isRequired,context:u().oneOfType([u().string,u().objectOf(u().any)]),onComponentSelect:u().func,fetchFailed:u().func},W.defaultProps={context:"",onComponentSelect:null,fetchFailed:null};var J=n(48012),K=n(66787);function Q(e,t){(null==t||t>e.length)&&(t=e.length);for(var n=0,c=new Array(t);n<t;n++)c[n]=e[n];return c}var X=(0,a.Z)((function(e){return{root:{"&.showMain":{"& .context":{"& li":{display:"none"}},"& li":{backgroundColor:e.palette.colors.black[200],"& span.MuiTypography-root":{backgroundColor:e.palette.colors.black[200]},"&.Mui-selected":{borderColor:"transparent","&:not(:hover)":{"& svg":{fill:e.palette.colors.grey[300]},"& .swarmLink":{color:e.palette.colors.grey[300]}}}}},"&.showContext":{"& .main li":{display:"none"}}},toggle:{color:e.palette.colors.grey[300],fill:e.palette.colors.grey[300],backgroundColor:e.palette.colors.black[200],padding:e.spacing(0,0,0,.5),borderRadius:0,width:"100%",justifyContent:"inherit",height:e.spacing(3.5),minWidth:0,"&:hover":{color:e.palette.colors.white[0],fill:e.palette.colors.white[0],backgroundColor:e.palette.colors.black[200]},"& svg":{padding:e.spacing(0,.25,0,2.5)},"& .mainMenuBtn":{textTransform:"uppercase"}}}})),Y=function(){var e,t,n=X(),a=(0,l.$)("menu").t,d=(0,r.v9)(K.p),u=(0,r.I0)(),p=(e=(0,c.useState)(!1),t=2,function(e){if(Array.isArray(e))return e}(e)||function(e,t){var n=null==e?null:"undefined"!=typeof Symbol&&e[Symbol.iterator]||e["@@iterator"];if(null!=n){var c,r,a=[],l=!0,o=!1;try{for(n=n.call(e);!(l=(c=n.next()).done)&&(a.push(c.value),!t||a.length!==t);l=!0);}catch(e){o=!0,r=e}finally{try{l||null==n.return||n.return()}finally{if(o)throw r}}return a}}(e,t)||function(e,t){if(e){if("string"==typeof e)return Q(e,t);var n=Object.prototype.toString.call(e).slice(8,-1);return"Object"===n&&e.constructor&&(n=e.constructor.name),"Map"===n||"Set"===n?Array.from(e):"Arguments"===n||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)?Q(e,t):void 0}}(e,t)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()),f=p[0],m=p[1],g=(0,r.v9)(O.VI),v=function(e){u((0,J.v)(e))},h=function(){v(null),m(!f)};return(0,c.useEffect)((function(){var e;"classic"===g&&("function"!=typeof Event?(e=document.createEvent("Event")).initEvent("navigation-loaded",!0,!1):e=new Event("navigation-loaded",{bubbles:!0}),document.dispatchEvent(e))}),[g]),c.createElement(s.Z,{className:"swarmNavigationBody ".concat(n.root," ").concat(d?f?"showMain":"showContext":"")},d?c.createElement(c.Fragment,null,c.createElement(o.Z,{className:"menuToggle ".concat(n.toggle),onClick:function(){m(!f)},disableRipple:!0,startIcon:c.createElement(V,{variant:"toggle"})},c.createElement(i.Z,{variant:"body2",className:"smallest mainMenuBtn"},a("main"))),c.createElement(U,{id:"context",context:d,fetchFailed:h})):null,c.createElement(U,{id:"main",onComponentSelect:v,fetchFailed:h}))}}}]);