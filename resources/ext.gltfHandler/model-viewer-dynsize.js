$(() => {
    // simulate width=100%, height=auto using javascript
    const elements = document.querySelectorAll(".model-viewer-dynsize[data-width][data-height]")
    window.addEventListener("resize", _ => {
        for(const element of elements){
            const figure_node = element.closest("figure")
            if(figure_node === null || figure_node.parentNode === null){
                continue
            }

            const width = parseFloat(element.getAttribute("data-width"))
            const height = parseFloat(element.getAttribute("data-height"))
            const available_space = figure_node.parentNode.getBoundingClientRect()

            const new_width = Math.min(available_space["width"], width)
            element.style.width = new_width + "px"
            element.style.height = ((height / width) * new_width) + "px"
        }
    })
})